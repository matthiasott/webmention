<?php

use craft\config\GeneralConfig;

return GeneralConfig::create()
    ->devMode(true)
    ->allowAdminChanges(true)
    ->omitScriptNameInUrls(true)
    ->disallowRobots(true);
