<?php
namespace benbun\choujiang;

use think\App;
use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class MakeDemo extends Command {

    protected $type = "demo";

    protected function configure() {
        parent::configure();
        $this->setName('demo:gd')
            ->setDescription('Create a new gd demo');
    }

    protected function getStub() {
        return __DIR__ . '/../stubs/gd.stub';
    }

    protected function getNamespace($appNamespace, $module) {
        return $module ? ($appNamespace . '\\' . $module) . '\controller' : $appNamespace . '\controller';
    }

    protected function execute(Input $input, Output $output) {
        // $name = trim($input->getArgument('name'));
        $name = 'demo/gd';
        $classname = $this->getClassName($name);

        $pathname = $this->getPathName($classname);

        if (is_file($pathname)) {
            $output->writeln('<error>' . $classname . ' already exists!</error>');
            return false;
        }

        if (!is_dir(dirname($pathname))) {
            mkdir(strtolower(dirname($pathname)), 0755, true);
        }
        file_put_contents($pathname, $this->buildClass($classname));

        $output->writeln('<info>' . $classname . ' created successfully.</info>');

    }

    protected function buildClass($name) {
        $stub = file_get_contents($this->getStub());

        $namespace = trim(implode('\\', array_slice(explode('\\', $name), 0, -1)), '\\');

        $class = str_replace($namespace . '\\', '', $name);

        return str_replace(['{%className%}', '{%namespace%}', '{%app_namespace%}'], [
            $class,
            $namespace,
            App::$namespace,
        ], $stub);

    }

    protected function getPathName($name) {
        $name = str_replace(App::$namespace . '\\', '', $name);

        return APP_PATH . str_replace('\\', '/', $name) . '.php';
    }

    protected function getClassName($name) {
        $appNamespace = App::$namespace;

        if (strpos($name, $appNamespace . '\\') === 0) {
            return $name;
        }

        if (Config::get('app_multi_module')) {
            if (strpos($name, '/')) {
                list($module, $name) = explode('/', $name, 2);
            } else {
                $module = 'common';
            }
        } else {
            $module = null;
        }

        if (strpos($name, '/') !== false) {
            $name = str_replace('/', '\\', $name);
        }

        return $this->getNamespace($appNamespace, $module) . '\\' . $name;
    }
}