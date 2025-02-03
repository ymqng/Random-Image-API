<?php
// ==============================================
// 图片随机服务API - 支持刷新更新版
// ==============================================

// 生产环境建议关闭错误显示
// error_reporting(0);

// ==============================================
// 配置部分
// ==============================================
define('IMAGE_BASE_DIR', __DIR__ . '/images/');
define('STATS_FILE', __DIR__ . '/stats/counter.json');
define('LIBRARY_MAP', [
    'pc'   => 'landscape',   // 横屏库
    'pe'   => 'portrait',    // 竖屏库
    'bs'   => 'baisi',       // 白丝写真
    'miku' => 'Hatsune_Miku' // 初音未来库
]);
define('HMAC_SECRET', 'luotianyi-66ccff'); // 重要！需修改为随机字符串

// ==============================================
// 主处理逻辑
// ==============================================
try {
    // 强制禁用缓存
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // 解析请求路径
    $requestPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

    // 处理统计查看请求
    if ($requestPath === 'stats') {
        handleStatsRequest();
        exit;
    }

    // 主图片处理逻辑
    handleImageRequest();

} catch (Exception $e) {
    http_response_code(500);
    exit('Internal Server Error');
}

// ==============================================
// 核心功能函数
// ==============================================
/**
 * 处理图片请求
 */
function handleImageRequest() {
    // 1. 获取后缀标识
    $pathSegments = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
    $suffix = $pathSegments[0] ?? '';

    // 2. 自动设备检测
    if (empty($suffix)) {
        $isMobile = preg_match('/Mobile|Android|iPhone/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $suffix = $isMobile ? 'pe' : 'pc';
    }

    // 3. 验证库有效性
    if (!isset(LIBRARY_MAP[$suffix])) {
        http_response_code(404);
        exit('Invalid library suffix');
    }

    // 4. 获取目标目录
    $targetDir = realpath(IMAGE_BASE_DIR . LIBRARY_MAP[$suffix]);
    if (!$targetDir || strpos($targetDir, realpath(IMAGE_BASE_DIR)) !== 0) {
        http_response_code(403);
        exit('Invalid library path');
    }

    // 5. 处理哈希参数
    $fileHash = $_GET['h'] ?? '';
    
    if (empty($fileHash)) {
        // 新请求：生成新随机图片
        $imagePath = getRandomImage($targetDir);
        updateStatistics($suffix);
        $hash = generateFileHash(basename($imagePath));
        outputImage($imagePath, false);
    } else {
        // 验证哈希并输出图片
        handleImageOutput($targetDir, $fileHash);
    }
}

/**
 * 处理图片输出（含下载功能）
 */
function handleImageOutput($targetDir, $fileHash) {
    $images = glob("{$targetDir}/*.{jpg,jpeg,png,gif}", GLOB_BRACE);
    
    foreach ($images as $imagePath) {
        $currentHash = generateFileHash(basename($imagePath));
        if (hash_equals($fileHash, $currentHash)) {
            // 最终安全验证
            if (!is_file($imagePath) || strpos(realpath($imagePath), $targetDir) !== 0) {
                http_response_code(403);
                exit('Path validation failed');
            }
            outputImage($imagePath, isset($_GET['download']));
        }
    }

    http_response_code(404);
    exit('File not found');
}

/**
 * 统一图片输出方法
 */
function outputImage($imagePath, $isDownload) {
    $mime = mime_content_type($imagePath);
    header("Content-Type: {$mime}");
    
    if ($isDownload) {
        header('Content-Disposition: attachment; filename="'.basename($imagePath).'"');
    } else {
        // 添加随机参数防止浏览器缓存
        header('Content-Disposition: inline; filename="'.basename($imagePath).'"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() - 3600) . ' GMT');
    }
    
    readfile($imagePath);
    exit;
}

// ==============================================
// 统计功能（保持不变）
// ==============================================
function updateStatistics($suffix) {
    if (!file_exists(STATS_FILE)) {
        @mkdir(dirname(STATS_FILE), 0755, true);
        file_put_contents(STATS_FILE, json_encode(['total' => 0]));
    }

    $fp = fopen(STATS_FILE, 'c+');
    if (flock($fp, LOCK_EX)) {
        $stats = json_decode(stream_get_contents($fp), true) ?? ['total' => 0];
        
        if (!isset($stats[$suffix])) $stats[$suffix] = 0;
        $stats['total']++;
        $stats[$suffix]++;
        
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($stats));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function handleStatsRequest() {
    if (!file_exists(STATS_FILE)) {
        http_response_code(503);
        exit('Statistics system not initialized');
    }

    $stats = json_decode(file_get_contents(STATS_FILE), true);
    if ($stats === null) {
        http_response_code(500);
        exit('Invalid statistics data');
    }

// 格式化输出
    $format = $_GET['format'] ?? 'json';
    switch (strtolower($format)) {
        case 'text':
            header('Content-Type: text/plain; charset=utf-8');
            $output = "=== 访问统计 ===\n";
            $output .= "总请求数: " . number_format($stats['total']) . "\n";
            foreach ($stats as $key => $value) {
                if ($key !== 'total') {
                    $output .= strtoupper($key) . ": " . number_format($value) . "\n";
                }
            }
            break;

        case 'json':
        default:
            header('Content-Type: application/json; charset=utf-8');
            $output = json_encode([
                'total' => $stats['total'],
                'details' => array_filter($stats, function($k) {
                    return $k !== 'total';
                }, ARRAY_FILTER_USE_KEY)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    exit($output);
}

// ==============================================
// 工具函数
// ==============================================
function getRandomImage($targetDir) {
    $images = glob("{$targetDir}/*.{jpg,jpeg,png,gif}", GLOB_BRACE);
    if (empty($images)) {
        http_response_code(404);
        exit('No images available');
    }
    return $images[array_rand($images)];
}

function generateFileHash($filename) {
    return hash_hmac('sha256', $filename, HMAC_SECRET);
}