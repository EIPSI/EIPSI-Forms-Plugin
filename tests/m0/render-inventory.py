#!/usr/bin/env python3
"""Render M0 runtime profiles and source cross references without touching WordPress."""
import argparse
import json
from collections import Counter, defaultdict
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('profiles', nargs='+', type=Path)
parser.add_argument('--output', required=True, type=Path)
args = parser.parse_args()
profiles = [json.loads(p.read_text()) for p in args.profiles]
root = Path(__file__).resolve().parents[2]
source = {(r['file'], r['line'], r['kind']): r for d in profiles for r in d['source']}
loaded = {r['file'] for d in profiles for r in d['included']}
rows = []
def cell(value):
    if isinstance(value, (list, dict)):
        value = json.dumps(value, ensure_ascii=False)
    return str(value or '—').replace('|', '\\|').replace('\n', ' ').replace('\r', ' ')
def table(headers, values):
    rows.append('| ' + ' | '.join(headers) + ' |')
    rows.append('| ' + ' | '.join('---' for _ in headers) + ' |')
    rows.extend('| ' + ' | '.join(cell(v) for v in row) + ' |' for row in values)
    rows.append('')
def location(callback):
    path = callback.get('file')
    if path and 'EIPSI-Forms-Plugin/' in path:
        path = path.split('EIPSI-Forms-Plugin/', 1)[1]
    return f"{path}:{callback.get('line')}" if path else 'WordPress / dinámico'
def registrations(kind, name, callback=None):
    found = []
    for r in source.values():
        if r['kind'] == kind and (r['name'] == name or r['name'] is None):
            if callback and not any(callback in arg for arg in r['args']):
                continue
            if r['file'] in loaded:
                found.append(f"{r.get('owner','contexto no resuelto')} — {r['file']}:{r['line']}")
    return '; '.join(found) or 'registro dinámico: ver fuente/JSON'

rows += ['# Inventario ejecutable M0', '',
         f"Capturas: PHP {profiles[0]['php']}, WordPress {profiles[0]['wordpress']}, plugin {profiles[0]['plugin']}; rama develop.", '',
         'Los perfiles son independientes. Frontend usa una página sintética con los shortcodes activos; admin recorre menús, pantallas del plugin y editor. Las solicitudes HTTP de la suite confirman el recorrido real de admin/formulario/assets. El inventario registra contratos, no demuestra todos los caminos funcionales.', '',
         '`activo`: registro observado y callable/archivo existente; `legacy`: deprecated explícito; `roto`: callable/archivo ausente; `indeterminado`: expresión dinámica o camino no observado. Una referencia textual no es prueba de consumo. No inferir que un archivo sin observación puede borrarse.', '',
         'Regeneración: `tests/m0/inventory.php` en Docker aislado → JSON por perfil → `python3 tests/m0/render-inventory.py FRONTEND.json ADMIN.json --output INVENTARIO.md`. Las expresiones completas y las referencias separadas están en JSON.', '']
table(['Perfil', 'Archivos incluidos', 'Callbacks', 'Shortcodes', 'REST', 'Cron programado', 'Assets', 'Bloques'],
      [(d['profile'], *(len(d[k]) for k in ['included','hooks','shortcodes','rest','cron','assets','blocks'])) for d in profiles])
rows += ['## Includes y requires', '', 'Orden global real de inclusión de PHP; includes repetidos con require_once no ejecutan nuevamente el archivo.', '']
for d in profiles:
    rows += [f"### Orden observado: {d['profile']}", '']
    table(['Orden', 'Archivo incluido', 'Estado'], [(r['order'],r['file'],'activo') for r in d['included']])
rows += ['### Declaraciones de includes y requires', '', 'Las condiciones y expresiones no resueltas se mantienen indeterminadas. Ver las tablas de callbacks para los consumidores concretos de cada servicio cargado.', '']
table(['Quién requiere / consumidor', 'Archivo:línea', 'Expresión', 'Destino resuelto', 'Estado'],
      [(r.get('owner'),f"{r['file']}:{r['line']}",r['args'][0],r.get('resolved_targets',[]),r['status']) for r in source.values() if r['kind']=='include'])

hooks = {}
for d in profiles:
    for h in d['hooks']:
        key = (h['hook'],h['callback'],h['file'],h['line'],h['priority'])
        if key not in hooks:
            hooks[key] = dict(h, profiles=[])
        hooks[key]['profiles'].append(d['profile'])
rows += ['## Hooks / actions / filters / AJAX', '', 'Prioridad y orden dentro de ella son los efectivos de WP_Hook. Las actions AJAX se consumen desde UI o clientes externos; los hooks de WordPress los despacha core. `eipsi_save_wave_nudges` tiene dos declaraciones, pero un único callback efectivo por prioridad.', '']
def registrar(h):
    found = []
    for pos in h['registrations']:
        matches = [r for r in source.values() if f"{r['file']}:{r['line']}" == pos]
        found.extend(f"{r.get('owner')} — {pos}" for r in matches)
    return '; '.join(found) or 'dinámico / core; definición en columna archivo'
table(['Hook', 'Quién registra', 'Callback consumidor', 'Emisor / UI encontrado', 'Prioridad / orden / args', 'Archivo callback', 'Perfiles', 'Estado'],
      [(h['hook'],registrar(h),h['callback'],'; '.join(h['consumers']) or ('WordPress admin-ajax / cliente externo no identificado' if h['hook'].startswith('wp_ajax_') else 'core/evento: ver fuente'),f"{h['priority']} / {h['order_at_priority']} / {h['accepted_args']}",f"{h['file']}:{h['line']}",', '.join(h['profiles']),h['status']) for h in sorted(hooks.values(),key=lambda h:(h['hook'],h['priority'],h['order_at_priority']))])
rows += ['## Shortcodes', '']
shortcodes = {tag: cb for d in profiles for tag,cb in d['shortcodes'].items()}
table(['Shortcode', 'Quién registra', 'Consumidor', 'Orden', 'Archivo callback', 'Estado'],
      [(tag,registrations('add_shortcode',tag),cb['callback']+' ← do_shortcode/contenido de páginas','orden de includes; registro único',location(cb),'activo' if cb['callable'] else 'roto') for tag,cb in shortcodes.items()])
rows += ['## REST', '']
rest = {r['route']:r for d in profiles for r in d['rest']}
rest_consumers = {'/eipsi/v1/pool-detect':'src/blocks/pool-block/edit.js', '/eipsi/v1/pool-config':'src/blocks/pool-block/edit.js', '/eipsi/v1/pool-assign':'sin cliente REST interno encontrado; pool-join usa AJAX', '/eipsi/v1/pool-analytics':'sin cliente REST interno identificado', '/eipsi/v1/randomization-config':'src/blocks/randomization-block/edit.js / cliente REST', '/eipsi/v1/randomization-detect':'src/blocks/randomization-block/edit.js / cliente REST'}
table(['Ruta', 'Métodos', 'Registrante / archivo', 'Handler consumidor', 'Cliente', 'Auth', 'Orden', 'Estado'],
      [(route,','.join(r['methods']),registrations('register_rest_route',None,r['callback']['callback']),r['callback']['callback'],rest_consumers.get(route,'indeterminado'),r['permission']['callback']+' '+location(r['permission']),'rest_api_init; ver hooks', 'activo' if r['callback']['callable'] else 'roto') for route,r in rest.items()])
rows += ['## Assets JS / CSS', '', 'Registro no implica enqueue en toda página. `deps` fija orden; group=1 implica footer. Se cruzan declaraciones y perfiles observados; referencias file: de bloques se validan con la suite.', '']
assets = {}
for d in profiles:
    for a in d['assets']:
        key = (a['kind'],a['handle'])
        if key not in assets: assets[key] = dict(a,profiles=[],queued=[])
        assets[key]['profiles'].append(d['profile'])
        if a['enqueued']: assets[key]['queued'].append(d['profile'])
def asset_status(a):
    if 'EIPSI-Forms-Plugin/' in str(a['src']):
        target = root/str(a['src']).split('EIPSI-Forms-Plugin/',1)[1].split('?',1)[0]
        return 'activo' if target.is_file() else 'roto'
    return 'indeterminado'
def asset_reg(a):
    found = []
    for r in source.values():
        if r['kind'] in ['wp_register_script','wp_enqueue_script','wp_register_style','wp_enqueue_style'] and r['name'] == a['handle']:
            found.append(f"{r.get('owner')} — {r['file']}:{r['line']}")
    return '; '.join(found) or 'register_block_type_from_metadata ← build/blocks/*/block.json'
table(['Tipo / handle', 'Quién registra', 'Consumidor / enqueue perfil', 'Orden deps / group', 'Archivo', 'Estado'],
      [(a['kind']+' / '+a['handle'],asset_reg(a),'WordPress '+', '.join(a['queued'])+'; dependientes: '+', '.join(b['handle'] for b in assets.values() if a['handle'] in b['deps']),f"{a['deps']} / {a['group']}",a['src'],asset_status(a)) for a in assets.values()])
rows += ['## Bloques Gutenberg', '']
blocks = {b['name']:b for d in profiles for b in d['blocks']}
block_files = {}
for manifest in (root/'build/blocks').glob('*/block.json'):
    block_files[json.loads(manifest.read_text())['name']] = str(manifest.relative_to(root))
table(['Bloque', 'Registrante', 'Consumidor', 'Orden / scripts', 'Archivo', 'Estado'],
      [(name,'eipsi_forms_register_blocks ← init','editor / do_blocks; '+(b['render_callback']['callback'] if b['render_callback'] else 'markup guardado'),b['editor_scripts'],block_files.get(name,'registro dinámico / indeterminado'),'activo') for name,b in blocks.items()])
rows += ['## Programación cron: declaraciones ejecutables', '', 'La matriz funcional y las discrepancias están en Caracterización M0. Aquí se conserva cada programador, incluidas expresiones dinámicas; un evento de dominio no es necesariamente un cron.', '']
table(['Hook / expresión', 'Programador', 'Archivo', 'Frecuencia / argumento', 'Estado'],
      [(r['name'] or r['args'][2 if r['kind']=='wp_schedule_event' else 1],r.get('owner'),f"{r['file']}:{r['line']}",r['args'][1] if r['kind']=='wp_schedule_event' else 'single', 'activo' if any(e['hook']==r['name'] for d in profiles for e in d['cron']) else 'indeterminado / contextual') for r in source.values() if r['kind'].startswith('wp_schedule')])
rows += ['## Declaraciones no observadas / restantes', '', 'Inventario estático complementario; estas filas no habilitan PURGA 2. Se conservan registros dentro de callbacks aún no ejercitados y expresiones dinámicas.', '']
table(['Tipo', 'Nombre / expresión', 'Owner', 'Archivo', 'Orden / prioridad declarada', 'Estado'],
      [(r['kind'],r['name'] or (r['args'][0] if r['args'] else ''),r.get('owner'),f"{r['file']}:{r['line']}",r['args'][2] if r['kind'] in ['add_action','add_filter'] and len(r['args'])>2 else 'por defecto / expresión dinámica',r['status']) for r in source.values() if r['kind']!='include' and r['status']!='activo'])
args.output.write_text('\n'.join(rows)+'\n')
print(json.dumps({'hooks':len(hooks),'ajax_callbacks':sum(h['hook'].startswith('wp_ajax_') for h in hooks.values()),'shortcodes':len(shortcodes),'rest':len(rest),'assets':len(assets),'blocks':len(blocks),'source':len(source)},ensure_ascii=False))
