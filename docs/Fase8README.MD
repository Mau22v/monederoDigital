# Fase 8 — Pago con tickets y canje

Copia el contenido de este zip sobre `~/proyectoFinal` (respeta las carpetas `banco/`, `tienda/` y `docs/`). Reemplaza los archivos que ya existan.

## Archivos nuevos o cambiados
**banco/**: app/Services/{FirmaTienda,CanjeadorTickets}.php, app/Http/Controllers/CanjeController.php, app/Support/Validar.php, app/Providers/AppServiceProvider.php, routes/api.php, tests/Feature/CanjeTest.php
**tienda/**: database/migrations/2026_11_14_100001_add_pago_to_pedidos_table.php, app/Models/Pedido.php, app/Services/{BancoCliente,BancoRechazo,BancoNoDisponible,ProcesadorPagos,CertificadoUsuario,ProcesadorSobre}.php, app/Support/{Contexto,Validar}.php, app/Http/Controllers/PedidoController.php, app/Providers/AppServiceProvider.php, app/Console/Commands/ClienteDemo.php, routes/api.php, tests/{BancoFalso,TiendaTestCase}.php, tests/Feature/PagosTest.php
**docs/**: contrato-integracion.md (v1.5), banco-base-de-datos.md, tienda-base-de-datos.md, diagrama-pago-canje.png

## Pasos
1. Banco: `cd ~/proyectoFinal/banco && php artisan test` → esperado 146 pruebas en verde (108 + 38 de canje).
2. Tienda: `cd ~/proyectoFinal/tienda && php artisan migrate` (agrega 5 columnas a `pedidos`; NO uses migrate:fresh si quieres conservar datos) y `php artisan test` → esperado 118 pruebas en verde (68 + 50 de pago).
3. Levanta los dos servidores (dos terminales): `php artisan serve --port=8001` en banco y `php artisan serve --port=8002` en tienda. Reinicia ambos tras copiar los archivos.
4. En la tienda: `php artisan cliente:demo` → esperado: bloques 1 a 24, todos `[OK]`, y "TODO CORRECTO".
5. `php artisan bitacora:ver` y `php artisan tienda:resumen` (tienda); `php artisan bitacora:ver` y `php artisan banco:resumen` (banco): deben verse el canje, los TICKET_INVALIDO, TICKET_GASTADO, FIRMA_INVALIDA, etc.
6. Git: `git status` y confirma que NO aparece ningún `.pem` privado; luego `git add . && git commit -m "Fase 8: pago con tickets y canje"`.

La tienda necesita `BANCO_URL=http://localhost:8001` (ya es el valor por omisión) y la carpeta `claves-publicas/` con `banco_rsa_pub.pem`.
