<?php

use App\Mcp\Servers\SpecifyServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', SpecifyServer::class)->middleware('auth:sanctum');
Mcp::local('specify', SpecifyServer::class);
