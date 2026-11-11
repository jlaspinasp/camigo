<?php
// Secret used to turn student ID numbers into one-way fingerprints (a keyed hash).
// Keep this file private. Do NOT change the value after people have been verified,
// or old ID numbers will stop matching new ones.
define('VERIFY_SECRET', '4b93a1069d1833a535e5d12407a4d4f2ae338b930b7b0d293dda4cb1798a1d92');