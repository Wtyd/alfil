<?php

namespace Wtyd\Alfil\App\Updater;

use Humbug\SelfUpdate\Strategy\GithubStrategy;
use LaravelZero\Framework\Components\Updater\Strategy\StrategyInterface;
use Phar;
use Wtyd\Alfil\Build\Build;

class CustomStrategy extends GithubStrategy implements StrategyInterface
{
    /**
     * Returns the Download Url.
     *
     * @param array $package
     */
    protected function getDownloadUrl(array $package): string
    {
        $downloadUrl = parent::getDownloadUrl($package);
        $downloadUrl = str_replace('releases/download', 'raw', $downloadUrl);
        $build = new Build();
        return $downloadUrl . $build->getBuildPath() . DIRECTORY_SEPARATOR . basename(Phar::running());
    }
}
