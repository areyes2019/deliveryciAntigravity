<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AiRefactor extends BaseCommand
{
    protected $group       = 'AI';
    protected $name        = 'ai:refactor';
    protected $description = 'Genera prompt de refactor para un módulo';

    public function run(array $params)
    {
        if (empty($params[0])) {
            CLI::error('Debes indicar el módulo. Ejemplo: php spark ai:refactor orders');
            return;
        }

        $module = strtolower($params[0]);

        // Paths
        $refactorPath = ROOTPATH . 'docs/prompts/refactor.md';
        $specPath     = ROOTPATH . "docs/modules/{$module}.md";

        // Archivos del módulo (ajústalos si usas otros nombres)
        $modelPath      = APPPATH . "Models/" . ucfirst($module) . "Model.php";
        $controllerPath = APPPATH . "Controllers/Api/V1/" . ucfirst($module) . "Controller.php";
        $servicePath    = APPPATH . "Services/" . ucfirst($module) . "Service.php";

        // Leer archivos
        $refactor = $this->readFile($refactorPath);
        $spec     = $this->readFile($specPath);
        $model    = $this->readFile($modelPath);
        $controller = $this->readFile($controllerPath);
        $service  = $this->readFile($servicePath);

        // Construir prompt
        $prompt = $this->buildPrompt($refactor, $spec, $controller, $service, $model);

        // Guardar
        $outputDir = WRITEPATH . 'ai/prompts/';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $outputFile = $outputDir . "{$module}_refactor.txt";
        file_put_contents($outputFile, $prompt);

        CLI::success("Prompt generado en: {$outputFile}");
    }

    private function readFile($path)
    {
        if (!file_exists($path)) {
            return "⚠️ Archivo no encontrado: {$path}\n";
        }

        return file_get_contents($path);
    }

    private function buildPrompt($refactor, $spec, $controller, $service, $model)
    {
        return <<<EOT
Eres un desarrollador senior trabajando en un sistema de delivery con CodeIgniter 4 + Vue 3.

## Contexto del sistema
$refactor

---

Este módulo ya existe y está en producción. Evita cambios innecesarios.

---

## Spec actualizado del módulo
$spec

---

## Código actual

--- Controller ---
$controller

--- Service ---
$service

--- Model ---
$model

---

## Tarea

Actualiza el código para alinearlo con el spec.

Requisitos:
- Detectar diferencias entre el spec y el código
- Aplicar solo los cambios necesarios
- No romper funcionalidad existente
- Mantener convenciones del proyecto
- Generar migraciones si hay cambios en DB
- Explicar cambios realizados

EOT;
    }
}
