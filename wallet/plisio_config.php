<?php

define(
    "PLISIO_SECRET_KEY",
    getenv('PLISIO_SECRET_KEY') ?: ''
);

define(
    "PLISIO_API_URL",
    "https://api.plisio.net/api/v1"
);

?>