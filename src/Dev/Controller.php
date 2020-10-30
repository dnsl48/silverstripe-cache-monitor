<?php

namespace DNSL48\SilverstripeCMS\CacheMonitor\Dev;

use SilverStripe\Control;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\CoreKernel;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\DevelopmentAdmin;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;



/**
 */
class Controller extends Control\Controller
{
    private static $url_handlers = [
        '' => 'index'
    ];

    private static $allowed_actions = [
        'index'
    ];

    private $recursionChain = [];

    protected function init()
    {
        parent::init();

        $allowAllCLI = DevelopmentAdmin::config()->get('allow_all_cli');
        $canAccess = (
            Director::isDev()
            // We need to ensure that DevelopmentAdminTest can simulate permission failures when running
            // "dev/tasks" from CLI.
            || (Director::is_cli() && $allowAllCLI)
            || Permission::check("ADMIN")
        );
        if (!$canAccess) {
            Security::permissionFailure($this);
        }
    }

    public function index()
    {
        $cacheBackends = $this->getCacheBackends();

        foreach ($cacheBackends as $service=>$backend) {
            $this->renderBackendStats($service, $backend);
        }
    }

    private function formatSize($value) {
        $bases = [
            [
                'base' => '1099511627776',
                'name' => 'TiB'
            ],
            [
                'base' => '1073741824',
                'name' => 'GiB'
            ],
            [
                'base' => '1048576',
                'name' => 'MiB'
            ],
            [
                'base' => '1024',
                'name' => 'KiB'
            ],
            [
                'base' => '1',
                'name' => 'B'
            ]
        ];

        $result = [];

        foreach ($bases as $base) {
            $calc = floor ($value / $base['base']);

			if ($calc) {
                $value = fmod ($value, $base['base']);
				$result[] = sprintf ('%d%s', $calc, $base['name']);
			}
		}

        if (!count($result)) {
            return '0';
        }

        return implode(' ', $result);
	}

    private function getDirectorySize($path) {
        $bytestotal = 0;
        $path = realpath($path);
        if($path!==false && $path!='' && file_exists($path)){
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $object){
                $bytestotal += $object->getSize();
            }
        }
        return $bytestotal;
    }

    private function getCacheBackends()
    {
        // $kern = Injector::inst()->get(CoreKernel::class);

        // $kernRef = new \ReflectionObject($kern);
        // $kernManifCacheFacRef = $kernRef->getMethod('buildManifestCacheFactory');
        // $kernManifCacheFacRef->setAccessible(true);
        // $manifestCache = $kernManifCacheFacRef->invoke($kern);

        $conf = Config::inst()->get(Injector::class);

        $services = array_reduce(
            array_keys($conf),
            static function ($services, $serviceName) {
                if (substr($serviceName, 0, strlen(CacheInterface::class)) == CacheInterface::class) {
                    $service = Injector::inst()->get($serviceName);
                    if ($service instanceof CacheInterface) {
                        $services[$serviceName] = $service;
                    }
                }

                return $services;
            },
            []
        );

        return $services;
    }

    private function renderBackendStats($service, $backend)
    {
        echo "<pre>";
        echo "<h1>$service</h1>".PHP_EOL;
        echo $this->explainCacheImplementation($backend).PHP_EOL;
        echo "</pre>";
    }

    private function explainCacheImplementation($cache) {
        if (in_array($cache, $this->recursionChain, true)) {
            throw new Exception('Recursion detected');
        }

        $this->recursionChain[] = $cache;

        if ($cache instanceof \SilverStripe\Versioned\Caching\ProxyCacheAdapter) {
            $description = $this->proxyCacheAdapter($cache);
        } else if ($cache instanceof \Symfony\Component\Cache\Simple\ChainCache) {
            $description = $this->explainChainCaches($cache);
        } else if ($cache instanceof \Symfony\Component\Cache\Simple\FilesystemCache) {
            $description = $this->explainFilesystemCache($cache);
        } else if ($cache instanceof \Symfony\Component\Cache\Simple\ApcuCache) {
            $description = $this->explainApcuCache($cache);
        } else {
            $impl = get_class($cache);
            $description = "$impl ( Unknown )";
        }

        return $description;
    }

    private function proxyCacheAdapter(\SilverStripe\Versioned\Caching\ProxyCacheAdapter $backend)
    {
        $ref = new \ReflectionObject($backend);
        $poolRef = $ref->getProperty('pool');
        $poolRef->setAccessible(true);
        $pool = $poolRef->getValue($backend);

        return get_class($backend)." (\n    ".$this->explainCacheImplementation($pool)."\n)";
    }

    private function explainChainCaches(\Symfony\Component\Cache\Simple\ChainCache $chain)
    {
        $ref = new \ReflectionObject($chain);
        $cachesRef = $ref->getProperty('caches');
        $cachesRef->setAccessible(true);
        $caches = $cachesRef->getValue($chain);

        $description = "";

        foreach ($caches as $cache) {
            $description .= "\n".$this->explainCacheImplementation($cache).",";
        }

        $description = str_replace("\n", "\n        ", $description);
        $description = get_class($chain)." (\n    [" . $description . "\n    ]\n)";

        return $description;
    }

    private function explainFilesystemCache(\Symfony\Component\Cache\Simple\FilesystemCache $cache)
    {
        $ref = new \ReflectionObject($cache);
        $directoryRef = $ref->getProperty('directory');
        $directoryRef->setAccessible(true);

        $directory = $directoryRef->getValue($cache);
        $freeSpace = $this->formatSize(disk_free_space($directory));
        $cacheSize = $this->formatSize($this->getDirectorySize($directory));

        if (in_array(PHP_OS, ['Linux'])) {
            $inodesUsed = (int) shell_exec("du -s --inodes ".escapeshellarg($directory));
            $inodesStat = explode(PHP_EOL, trim(shell_exec("df -i ".escapeshellarg($directory))));

            if (count($inodesStat) == 2) {
                $inodesStat[0] = preg_split('/\s+/', $inodesStat[0]);
                $inodesStat[1] = preg_split('/\s+/', $inodesStat[1]);

                $inodesFree = 0;
                $inodesTotal = 0;
                $inodesTotalUse = 0;
                $inodesTotalUsep = 0;

                for ($i=0; $i<count($inodesStat[0]); ++$i) {
                    $key = strtolower($inodesStat[0][$i]);
                    if ($key === 'ifree') {
                        $inodesFree = (int) $inodesStat[1][$i];
                    } else if ($key === 'iused') {
                        $inodesTotalUse = (int) $inodesStat[1][$i];
                    } else if ($key === 'iuse%') {
                        $inodesTotalUsep = (int) $inodesStat[1][$i];
                    } else if ($key === 'inodes') {
                        $inodesTotal = (int) $inodesStat[1][$i];
                    }
                }
            }

            if ($inodesUsed > 0 && $inodesFree > 0) {
                $inodesUsedPercent = round($inodesUsed / ($inodesFree / 100));
                $inodes = "\nInodes used% (used/free/total/totalUse): $inodesUsedPercent% ($inodesUsed / $inodesFree / $inodesTotal / $inodesTotalUse ($inodesTotalUsep%))";
            } else { $inodes = ''; }
        } else {
            $inodes = '';
        }

        $description = <<<EOD
Cache Size: $cacheSize
Free Space: $freeSpace $inodes
Location: $directory
EOD;

        $description = str_replace("\n", "\n    ", $description);
        return get_class($cache)." (\n    ".$description."\n)";
    }

    private function explainApcuCache(\Symfony\Component\Cache\Simple\ApcuCache $cache)
    {
        $enabled = $cache->isSupported() && apcu_enabled();
        $enabledValue = var_export($enabled, true);
        $info = '';

        if ($enabled) {
            $chinfo = apcu_cache_info(true);
            $meminfo = apcu_sma_info(true);

            $ref = new \ReflectionClass(\Symfony\Component\Cache\Simple\AbstractCache::class);
            $namespaceRef = $ref->getProperty('namespace');
            $namespaceRef->setAccessible(true);

            $namespace = $namespaceRef->getValue($cache);

            if ($namespace) {
                $namespaceIter = new \APCUIterator('/^'.preg_quote($namespace, '/').'/');
                $namespaceSize = $namespaceIter->getTotalSize();
                $namespaceCount = $namespaceIter->getTotalCount();
                $namespaceHits = $namespaceIter->getTotalHits();

                $info .= PHP_EOL.'Cache Size: '.$this->formatSize($namespaceSize)." ($namespaceCount items)";
                $info .= PHP_EOL.'Cache Hits: '.$namespaceHits;
            }

            $info .= PHP_EOL.'Free Space: '.$this->formatSize($meminfo['avail_mem']);
            $info .= PHP_EOL.'Total Used: '.$this->formatSize($chinfo['mem_size']). ' ('.$chinfo['num_entries'].' items)';
            $info .= PHP_EOL.'Total Hits: '.$chinfo['num_hits'];
            $info .= PHP_EOL.'Total Misses: '.$chinfo['num_misses'];

            $info .= PHP_EOL.'Namespace: '.$namespace;

            // $info .= PHP_EOL.'Info: '.print_r(apcu_cache_info(true), true).PHP_EOL;
            // $info .= print_r(apcu_sma_info(true), true);
        }

        $description = <<<EOD
Enabled: $enabledValue $info
EOD;

        $description = str_replace("\n", "\n    ", $description);
        return get_class($cache)." (\n    ".$description."\n)";
    }
}
