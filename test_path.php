<?php
$path = 'D:\proyectos\SEDERG_PAY\backend\src\Infrastructure\Identity' . '/../../../../backend/web/assets/sederg-pay-logo.png';
echo 'Resolved path: ' . $path . "\n";
echo 'File exists: ' . (is_file($path) ? 'YES' : 'NO') . "\n";
$data = @file_get_contents($path);
echo 'File readable: ' . ($data !== false ? 'YES' : 'NO') . "\n";
if ($data !== false) {
    echo 'Size: ' . strlen($data) . " bytes\n";
}