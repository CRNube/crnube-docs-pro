# CRNube Docs Pro

Complemento de [CRNube Docs](../crnube-docs): área protegida, reportes detallados y asistente de documentos con IA. Requiere CRNube Docs 0.6.0 o más reciente, activo (WordPress lo exige con el encabezado `Requires Plugins`).

**Requisitos:** PHP 8.1+, WordPress 6.5+.

## Licencia

**Documentos › Licencia**: pegue la clave (`CRND-XXXXX-XXXXX-XXXXX-XXXXX`). Se verifica con el servidor de licencias de crnube.com (plugin `crnube-licenses`), que registra el sitio; luego se revisa una vez al día.

- La licencia da **actualizaciones y soporte**. Si vence o se cancela, **todo sigue funcionando igual**: el área protegida de una cooperativa nunca se cierra ni se abre por una renovación pendiente.
- Las copias locales y de prueba (`localhost`, `*.test`, `*.local`) no usan cupo.
- **Actualizaciones firmadas**: cada paquete se descarga con un enlace firmado que dura 48 horas y, antes de instalarse, se verifica su firma Ed25519 con la llave pública `CRND_PRO_RELEASE_KEY` (en `crnube-docs-pro.php`). La llave secreta está solo en la computadora de quien publica, así que ni un servidor comprometido puede entregar un plugin modificado.
- Servidor de licencias: `https://crnube.com` por defecto; para pruebas, la constante `CRND_PRO_LICENSE_SERVER` o el filtro `crnd_pro_license_server`. La clave solo viaja por HTTPS (HTTP solo a `localhost`/`.test`).

## Área protegida

1. **Documentos › Acceso**: cree grupos (*Junta Directiva*, *Delegados 2026*…) y agregue personas por correo, con fecha de vencimiento opcional, o **importe un CSV** (columnas nombre, correo, vence; `;` o `,`; fechas AAAA-MM-DD o DD/MM/AAAA). Puede avisarles por correo con el enlace al portal. Quien no tiene cuenta recibe una con el rol **Miembro de documentos** (sin acceso al admin).
2. **Biblioteca**: en el menú de una categoría, **Quién puede abrirla…** → *Solo miembros* de uno o varios grupos (ninguno marcado = cualquier miembro). Aplica a sus subcategorías. Cada documento puede tener su propia excepción en la columna **Acceso** (o en la caja "Quién puede abrirlo" de la edición clásica).
3. **Documentos › Ajustes › Área protegida**: **Crear la página del portal** (`[crnube_docs_portal]`), mostrar los privados con candado u ocultarlos, y duración de la sesión.

- **Ingreso sin contraseña**: el miembro escribe su correo y recibe un código de 6 dígitos (10 minutos, un solo uso, 5 intentos). No se revela si un correo tiene acceso. Al vencer la membresía pierde el acceso solo.
- **Archivos privados**: se mueven a `uploads/crnd-private-{aleatorio}/` (con `.htaccess`/`web.config` que niegan todo); su URL directa deja de funcionar y solo se entregan tras verificar permisos, sin caché. La pantalla Acceso comprueba por HTTP que el servidor realmente los bloquea; en **Nginx** agregue `location ~* /wp-content/uploads/crnd-private- { deny all; }`.
- Un documento en varias categorías es privado si alguna lo es. Si se borra un grupo, sus documentos quedan solo para el personal (nunca se abren a todos por error).
- Cada vista/descarga privada queda en **Reportes** con el nombre del usuario.
- `[crnube_docs_portal category="actas"]` muestra en el portal solo esa categoría.

## Reportes

**Documentos › Reportes** muestra, para el periodo elegido (7, 30 o 90 días, este año, 12 meses o un rango propio) y opcionalmente para una categoría o un documento:

- Descargas, vistas, visitantes únicos y documentos con actividad, comparados con el periodo anterior.
- Gráfico de actividad en el tiempo (por día, o por mes en periodos largos), con los datos también en tabla.
- Documentos más consultados, actividad por categoría, dispositivos, navegadores, sistemas, países y hora del día.
- Registro de cada vista o descarga: fecha y hora, documento, dispositivo, navegador, sistema, país, página de origen y usuario (si inició sesión).
- Exportación CSV para Excel: **detalle** (una fila por evento) o **resumen por documento**.

Desde la Biblioteca, el menú de cada documento tiene **Ver estadísticas**.

**Qué cuenta**: *Vista* es abrir el documento en el navegador (botón Ver o el título); *Descarga* es el botón Descargar. No se cuentan bots, precargas del navegador ni clics repetidos de la misma persona en 30 segundos.

**Privacidad (Ley 8968)**: no se guarda la IP ni el navegador completo. Cada visitante recibe una clave anónima que cambia cada día. Quien envía "No rastrear" / *Global Privacy Control* se cuenta sin dispositivo ni país. El historial se borra solo después del plazo elegido en Ajustes (24 meses por defecto). El país solo se registra si el servidor lo informa (por ejemplo, detrás de Cloudflare); para servidores con proxy propio existe el filtro `crnd_visitor_ip`, y `crnd_visitor_country` para otra fuente de país.

## Asistente de documentos (IA)

Un mini chat público que responde **solo con los documentos públicos** y cita documento y página: "¿Cuál fue el excedente en 2024?", "Compare 2024 con 2025".

**Configurar** en **Documentos › Asistente**:

1. Elija el proveedor (**Claude**, **ChatGPT** o **Gemini**), pegue la clave de API y el modelo con sus precios. La clave se guarda cifrada, o puede definirse `CRND_AI_API_KEY` en `wp-config.php`.
2. Fije los topes de gasto diario y mensual (por defecto $2 y $30) y las preguntas por visitante (5 por hora, 15 por día). Ponga además un límite de gasto en la consola del proveedor.
3. Active **Cloudflare Turnstile** (gratis; se configura con la clave del sitio y la secreta): frena a los bots sin molestar a las personas.
4. **Probar la conexión**, encender el asistente y **Preparar documentos pendientes**.
5. Agregue `[crnube_docs_assistant]` (o con `category="estados-financieros"`) o el widget **Asistente de documentos** de Elementor.

**Cómo funciona**

- **Preparación, una sola vez por documento**: los PDF los transcribe la IA (tablas incluidas, con marcas de página); Word y Excel se leen en el propio sitio, sin costo. Si el archivo cambia, se prepara de nuevo. Los documentos restringidos **nunca** se preparan.
- **Cada pregunta** busca las páginas relevantes (por título, año, categoría y palabras; los años mencionados siempre se incluyen) y envía solo esos fragmentos, nunca los PDF completos.
- La IA tiene instrucciones de responder solo con esos fragmentos, citar cada dato, no inventar cifras, rechazar temas ajenos y no dar asesoría financiera. Si no encuentra nada relacionado, el asistente responde sin llamar a la IA.
- **Caché**: una pregunta ya respondida (con las mismas palabras) se contesta gratis durante 7 días, o hasta que cambien los documentos.

**Protección del gasto**: al llegar a un tope, el asistente dice que no está disponible y no hace más llamadas. Cada visitante se cuenta con una clave anónima que cambia cada día; no se guarda la IP. La pantalla muestra el gasto del día y del mes, las preguntas, las frenadas por límites y las más frecuentes (se pueden dejar de guardar; se borran tras el plazo elegido, 90 días por defecto).

**Elegir qué documentos usa**: por defecto, todos los documentos públicos. Para excluir alguno: en la Biblioteca, columna **Asistente** (por documento) o menú de la categoría › **Usar en el asistente…** (aplica a sus subcategorías); también en la caja **Asistente de documentos** de la pantalla del documento. Un documento excluido sigue en el sitio; solo el asistente lo ignora. Su texto preparado se conserva, así que volver a incluirlo no cuesta nada, y uno que nunca se preparó no gasta créditos.

**Si no está configurado o está apagado**, el shortcode y el widget no muestran nada a los visitantes.

## Desarrollo

Este repositorio va junto a `crnube-docs` en `wp-content/plugins/` (PHPStan lee sus clases de `../crnube-docs/src`).

```bash
composer install
npm install
npm run build           # apps de Acceso, Reportes y la extensión de la Biblioteca (build/ va en el repo)
composer check          # PHPCS + PHPStan nivel 6
npm run lint:js
php bin/translations.php   # text domain crnube-docs-pro; falla si falta un texto
```

### Publicar una versión

1. Suba la versión en `crnube-docs-pro.php` (encabezado y `CRND_PRO_VERSION`), compile y haga commit.
2. `git archive --format=zip --prefix=crnube-docs-pro/ -o crnube-docs-pro-X.Y.Z.zip HEAD`
3. `php ../crnube-licenses/bin/release.php sign crnube-docs-pro-X.Y.Z.zip` (usa la llave secreta de `~/.crnube/crnube-release.key`).
4. En crnube.com, **Licencias › Versiones**: suba el zip con la versión y la firma. Los sitios lo ven en menos de 12 horas.