<?php
// Agregar estas líneas dentro de routes/api.php (o incluir así):

require app_path('Modules/Auth/routes.php');
require app_path('Modules/Proveedores/routes.php');
require base_path('app/Modules/Documentos_Proveedor/routes.php');
require base_path('app/Modules/Ficha_Productos/routes.php');
