<?php
/**
 * Compatibility wrapper. This file used to be a byte-identical copy of
 * index.php, which meant every change had to be made in two places. The
 * .htaccess here lists index_new.php first in DirectoryIndex, so this is the
 * file that actually runs - it now just delegates to the one implementation.
 */
require __DIR__ . '/index.php';
