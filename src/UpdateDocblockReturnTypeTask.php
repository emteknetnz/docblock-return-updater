<?php

use SilverStripe\Dev\BuildTask;

class UpdateDocblockReturnTypeTask extends BuildTask
{
    public function run($request)
    {
        $dirs = [
            BASE_PATH . '/vendor/dnadesign/silverstripe-elemental',
            BASE_PATH . '/vendor/silverstripe/admin',
            BASE_PATH . '/vendor/silverstripe/asset-admin',
            BASE_PATH . '/vendor/silverstripe/assets',
            BASE_PATH . '/vendor/silverstripe/behat-extension',
            BASE_PATH . '/vendor/silverstripe/campaign-admin',
            BASE_PATH . '/vendor/silverstripe/cms',
            BASE_PATH . '/vendor/silverstripe/config',
            BASE_PATH . '/vendor/silverstripe/elemental-bannerblock',
            BASE_PATH . '/vendor/silverstripe/elemental-fileblock',
            BASE_PATH . '/vendor/silverstripe/errorpage',
            BASE_PATH . '/vendor/silverstripe/framework',
            BASE_PATH . '/vendor/silverstripe/graphql',
            BASE_PATH . '/vendor/silverstripe/login-forms',
            BASE_PATH . '/vendor/silverstripe/mimevalidator',
            BASE_PATH . '/vendor/silverstripe/mink-facebook-web-driver',
            BASE_PATH . '/vendor/silverstripe/serve',
            BASE_PATH . '/vendor/silverstripe/session-manager',
            BASE_PATH . '/vendor/silverstripe/siteconfig',
            BASE_PATH . '/vendor/silverstripe/testsession',
            BASE_PATH . '/vendor/silverstripe/vendor-plugin',
            BASE_PATH . '/vendor/silverstripe/versioned',
            BASE_PATH . '/vendor/silverstripe/versioned-admin',
            BASE_PATH . '/vendor/symbiote/silverstripe-gridfieldextensions',
        ];
        foreach ($dirs as $dir) {
            foreach (['src', 'code', 'tests'] as $d) {
                $subdir = "$dir/$d";
                if (file_exists($subdir)) {
                    $this->update($subdir);
                }
            }
        }
    }

    private function update(string $dir)
    {
        $paths = explode("\n", shell_exec("find $dir | grep .php"));
        $paths = array_filter($paths);
        foreach ($paths as $path) {
            if (is_dir($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if (!preg_match('#(\nabstract |\n)class (.+?)[ \n]#', $contents, $m)) {
                continue;
            }
            $class = $m[2];
            if (!preg_match('#\nnamespace (.+?);#', $contents, $m)) {
                continue;
            }
            $namespace = $m[1];
            $fqcn = $namespace . '\\' . $class;
            try {
                $reflClass = new ReflectionClass($fqcn);
            } catch (Error|Exception $e) {
                continue;
            }
            $reflMethods = $reflClass->getMethods();
            foreach ($reflMethods as $reflMethod) {
                if ($reflClass->getFileName() != $reflMethod->getFileName()) {
                    continue;
                }
                $returnType = $reflMethod->getReturnType();
                if ($returnType) {
                    continue;
                }
                $docblock = $reflMethod->getDocComment();
                if (!preg_match('#@return +([a-zA-Z0-9\|]+)#', $docblock, $m)) {
                    continue;
                }
                $oldReturnTypeDocblock = $m[1];
                $old = explode('|', strtolower($oldReturnTypeDocblock));
                $new = explode('|', $oldReturnTypeDocblock);
                $returnTypes = ['null', 'false', 'true'];
                $contents = file_get_contents($reflMethod->getFileName());
                $arr = explode("\n", $contents);
                $startLine = $reflMethod->getStartLine();
                $slicedArr = array_slice($arr, $startLine, $reflMethod->getEndLine() - $startLine);
                $methodContents = implode("\n", $slicedArr);
                foreach ($returnTypes as $returnType) {
                    $returnTypeIsBool = in_array($returnType, ['true', 'false']);
                    if (in_array($returnType, $old)) {
                        continue;
                    }
                    if (in_array('mixed', $old)) {
                        continue;
                    }
                    if ($returnTypeIsBool && (in_array('bool', $old) || in_array('boolean', $old))) {
                        continue;
                    }
                    if (strpos($methodContents, 'function') !== false) {
                        // return types within anonymous functions just make things too complex
                        continue;
                    }
                    if (strpos($methodContents, 'return ' . $returnType) !== false) {
                        $new[] = $returnType;
                    }
                    if (in_array('true', $new) && in_array('false', $new)) {
                        $new = array_filter($new, fn($v) => !in_array($v, ['true', 'false']));
                        $new[] = 'bool';
                    }
                }
                $newReturnTypeDocblock = implode('|', $new);
                if ($newReturnTypeDocblock == $oldReturnTypeDocblock) {
                    continue;
                }
                $method = $reflMethod->getName();
                $oldDocblock = $reflMethod->getDocComment();
                $newDocblock = str_replace(
                    "@return $oldReturnTypeDocblock",
                    "@return $newReturnTypeDocblock",
                    $oldDocblock
                );
                $contents = preg_replace(
                    sprintf("#(?s)%s\n([^\n]+)function %s#", trim(preg_quote($oldDocblock)), $method),
                    trim($newDocblock) . "\n" . '$1function ' . $method,
                    $contents
                );
                file_put_contents($path, $contents);
                echo "Updated return type for $dir $class::$method()\n";
            }
        }
    }
}