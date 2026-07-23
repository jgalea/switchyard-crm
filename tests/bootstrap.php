<?php

declare( strict_types=1 );

define( 'CONDUIT_TESTING', true );
define( 'CONDUIT_VERSION', '1.0.0' );
define( 'CONDUIT_DIR', dirname( __DIR__ ) . '/' );
define( 'CONDUIT_URL', 'https://example.test/wp-content/plugins/conduit-crm/' );
define( 'CONDUIT_FILE', dirname( __DIR__ ) . '/conduit-crm.php' );

define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MINUTE_IN_SECONDS', 60 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
