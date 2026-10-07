<?php

namespace Tests\Feature;

use RuntimeException;

/** Seau de fichiers injoignable : le pilote R2/S3 ne repond plus. */
class DiskUnavailable extends RuntimeException {}
