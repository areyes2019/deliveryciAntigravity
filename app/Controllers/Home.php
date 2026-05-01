<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index()
    {
        return $this->vue();
    }

    public function vue()
    {
        $indexFile = FCPATH . 'index.html';
        if (file_exists($indexFile)) {
            return $this->response
                ->setHeader('Content-Type', 'text/html; charset=UTF-8')
                ->setBody(file_get_contents($indexFile));
        }
        return $this->response
            ->setStatusCode(503)
            ->setBody('Frontend no compilado. Ejecuta: cd frontend && npm run build');
    }
}
