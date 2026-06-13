<?php

$directories = [
    __DIR__ . '/Modules/Inventory/app/',
    __DIR__ . '/Modules/Supplier/app/',
    __DIR__ . '/Modules/Purchase/app/',
    __DIR__ . '/Modules/Sales/app/',
    __DIR__ . '/Modules/Product/app/',
    __DIR__ . '/Modules/Inbound/app/',
];

function processDirectory($dir) {
    if (!is_dir($dir)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getPathname());

            $pattern = '/\bint\s+\$(\b(?:id|itemId|transferId|poId|locationId)\b)/';
            $newContent = preg_replace($pattern, 'string \$$1', $content);

            $newContent = preg_replace('/\(int\)\s*\$(\b(?:id|product->id)\b)/', '\$$1', $newContent);

            if ($content !== $newContent) {
                file_put_contents($file->getPathname(), $newContent);
                echo "Updated: " . $file->getPathname() . "\n";
            }
        }
    }
}

foreach ($directories as $dir) {
    processDirectory($dir);
}
