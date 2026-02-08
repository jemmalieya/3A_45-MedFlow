<?php
header('Content-Type: text/plain');

echo "PHP: " . PHP_VERSION . PHP_EOL;
echo "GD loaded: " . (extension_loaded('gd') ? 'YES' : 'NO') . PHP_EOL;

if (extension_loaded('gd')) {
    print_r(gd_info());
} else {
    echo "=> GD is NOT enabled for the PHP used by the web server.\n";
}
