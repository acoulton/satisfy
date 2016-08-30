<?php
/**
 * Satisfy
 * @author Richard George <rgeorge@7city.com>
 * @author Doug Wright <douglas.wright@fitchlearning.com>
 */

namespace FitchLearning\Satisfy;


class Application
{

    protected $packages;
    protected $repoDefinition;
    protected $outputFile = false; // false => stdout

    /**
     * Load a file specifying the git repositories to be scanned for packages
     *
     * @param $filename
     * @throws \Exception
     */
    public function loadPackagesFromFile($filename)
    {
        $raw = file_get_contents($filename);

        if ($raw === false) {
            throw new \Exception("Cannot open $filename");
        }
        $packages = json_decode($raw, true);
        if (is_null($packages)) {
            throw new \Exception("Cannot parse package list in $filename");
        }
        $this->packages = $packages;
    }

    /*
      Correct JSON format for packages file is:
      {
    "frontend/fontawesome": {
        "url": "git://github.com/FortAwesome/Font-Awesome.git",
        "minversion": "2.0",
        "defaults": {
            "homepage": "http://fontawesome.io/"
        }
    },
    "frontend/bootstrap": {
        "url": "git://github.com/twbs/bootstrap.git",
        "minversion": "2.0",
        "defaults": [

        ]
    },
    "brightcove/brightcove-phpapi": {
        "url": "https://github.com/BrightcoveOS/PHP-MAPI-Wrapper.git",
        "defaults": {
            "autoload": {
                "classmap": [
                    "."
                ]
            }
        }
    }
    }

    */


    /**
     * Load a base satis.json file to which found packages will be added
     *
     * @param $filename
     * @throws \Exception
     */
    public function loadRepoDefinitionFromFile($filename)
    {
        $raw = file_get_contents($filename);

        if ($raw === false) {
            throw new \Exception("Cannot open $filename");
        }
        $repo = json_decode($raw, true);
        if (is_null($repo)) {
            throw new \Exception("Cannot parse repo definition in $filename");
        }

        if (!isset($repo['repositories'])) {
            throw new \Exception("Repo file must contain repositories member, even if empty");
        }
        $this->repoDefinition = $repo;
    }

    public function run()
    {
        $repoDefinition = $this->getRepoDefinition();

        $packages = $this->getRequiredPackageList();
        $repo = $this->addPackagesToRepo($packages, $repoDefinition);

        $output = json_encode($repo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($this->outputFile) {
            file_put_contents($this->outputFile, $output);
        } else {
            print $output;
        }
    }

    protected function getRemoteUrlRefs($vcsUrl)
    {
        $refs = [];
        $gitPath = 'git';
        $cmd = "$gitPath ls-remote --tags --heads " . escapeshellarg($vcsUrl);
        $returnValue = null;
        $output = [];

        exec($cmd, $output, $returnValue);

        if (!$returnValue) {
            foreach ($output as $reference) {
                $parts = preg_split('/\s+/', $reference, -1, PREG_SPLIT_NO_EMPTY);
                if (count($parts) == 2) {
                    if (preg_match('#refs/tags/(v?\d.*[^}])$#', $parts[1], $matches)) {
                        $refs[$this->getVersionFromString($matches[1])] = $matches[1];
                    } else if (preg_match('#refs/heads/(.*[^}])$#', $parts[1], $matches)) {
                        $refs['dev-' . $this->getVersionFromString($matches[1])] = $parts[0];
                    }

                }
            }
        }

        return $refs;
    }

    protected function getVersionFromString($string)
    {
        if (preg_match('/^v?(\d+\.\d+\.\d+)(-((rc|alpha|beta)\.?(\d+)))?$/', $string, $matches)) {
            $version = $matches[1];
            if (count($matches) >= 5) {
                $version .= '-' . $matches[4];
            }
            if (count($matches) >= 6) {
                $version .= $matches[5];
            }
        } else {
            $version = $string;
        }

        return $version;
    }


    /**
     * @return array
     */
    protected function getRequiredPackageList()
    {
        return $this->packages;
    }

    /**
     * Scan the VCS URIs in $packages and merge results into $repoDefinition
     *
     * TODO this is probably silly encapsulation
     *
     * @param $packages
     * @param $repoDefinition
     * @throws \Exception
     * @return
     */
    protected function addPackagesToRepo($packages, $repoDefinition)
    {
        $foundPackages = [];

        foreach ($packages as $name => $definition) {
            $vcsUrl = $definition['url'];
            $refs = $this->getRemoteUrlRefs($vcsUrl);

            foreach ($refs as $version => $ref) {
                if ( ! $this->shouldIncludeVersion($definition, $version)) {
                    continue;
                }

                if (isset($definition['defaults'])) {
                    $packageDefinition = $definition['defaults'];
                } else {
                    $packageDefinition = [];
                }

                if (isset($packageDefinition['description'])) {
                    $packageDefinition['description'] .= '; Autogenerated by satisfy';
                } else {
                    $packageDefinition['description'] = 'Autogenerated by satisfy';
                }

                $packageDefinition['name'] = $name;
                $packageDefinition['version'] = $version;
                $packageDefinition["source"] = [
                    "url" => $vcsUrl,
                    "type" => "git",
                    "reference" => $ref
                ];

                if ($downloadUrl = $this->getZipDownloadUrl($vcsUrl, $ref)) {
                    $packageDefinition['dist'] = [
                        'url'  => $downloadUrl,
                        'type' => 'zip',
                    ];
                }

                $foundPackages[] = [
                    "type" => "package",
                    "package" => $packageDefinition
                ];
            }
        }
        $repoDefinition['repositories'] = array_merge($repoDefinition['repositories'], $foundPackages);

        return $repoDefinition;
    }
    
    /**
     * @param array  $definition
     * @param string $version
     *
     * @return bool
     */
    protected function shouldIncludeVersion($definition, $version)
    {
        if ( ! isset($definition['minversion'])) {
            return true;
        }

        if ( ! preg_match('#^(dev-)?([0-9\.]+)$#', $version, $matches)) {
            // version is not a numeric version or branch, so can't be compared
            return true;
        }

        $numeric_version = array_pop($matches);
        return version_compare($numeric_version, $definition['minversion']) >= 0;
    }

    /**
     * @param string $vcsUrl
     * @param string $ref
     *
     * @return string
     */
    protected function getZipDownloadUrl($vcsUrl, $ref)
    {
        if (preg_match('#https://github.com/([^/]+)/([^/]+?)(\.git)?$#', $vcsUrl, $matches)) {
            // Build github zipball download URL where possible
            return 'https://api.github.com/repos/'.$matches[1].'/'.$matches[2].'/zipball/'.$ref;
        }

        return NULL;
    }

    /**
     * @return array
     */
    protected function getRepoDefinition()
    {
        return $this->repoDefinition;
    }

    /**
     * @param boolean $outputFile
     */
    public function setOutputFile($outputFile)
    {
        $this->outputFile = $outputFile;
    }
}
