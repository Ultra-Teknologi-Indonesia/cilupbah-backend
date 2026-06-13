<?php

$dir = new RecursiveDirectoryIterator('Modules');
$iterator = new RecursiveIteratorIterator($dir);

foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Request.php')) {
        $path = $file->getPathname();
        $content = file_get_contents($path);

        if (str_contains($content, "'uuid'")) {
            $newContent = str_replace("'uuid'", "'string', 'size:32'", $content);
            file_put_contents($path, $newContent);
            echo "Fixed $path\n";
        }
    }
}
echo "Done.\n";
