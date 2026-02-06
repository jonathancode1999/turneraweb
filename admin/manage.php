<?php
require __DIR__.'/_inc.php'; require_login();

$slug = trim($_GET['c'] ?? '');
if($slug==='' || !client_slug_valid($slug) || !is_dir(client_dir($slug))){
  flash_set('err','Cliente inválido.');
  header('Location: dashboard.php'); exit;
}
$tab = $_GET['tab'] ?? 'business';

try{ $pdo = client_pdo($slug); }
catch(Throwable $e){ flash_set('err','No se pudo abrir DB: '.$e->getMessage()); header('Location: dashboard.php'); exit; }

if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  $action = $_POST['action'] ?? '';
  try{
    if($action==='save_business'){
      // Optional branding uploads (logo/cover) saved into client public/uploads/branding
      $logo = $biz['logo_path'] ?? '';
      $cover = $biz['cover_path'] ?? '';
      $clientUploads = client_dir($slug) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'branding';
      if(!is_dir($clientUploads)) @mkdir($clientUploads, 0777, true);

      $save_img = function(string $field, string $prefix) use ($clientUploads, $slug): ?string {
        if(!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;
        $f = $_FILES[$field];
        if(($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if(($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Error subiendo archivo: '.$field);
        if(($f['size'] ?? 0) > 4*1024*1024) throw new RuntimeException('Imagen demasiado grande (máx 4MB).');
        $tmp = $f['tmp_name'] ?? '';
        $info = @getimagesize($tmp);
        if(!$info) throw new RuntimeException('Archivo inválido (no es imagen).');
        $mime = $info['mime'] ?? '';
        $ext = ($mime==='image/png') ? 'png' : (($mime==='image/jpeg') ? 'jpg' : (($mime==='image/webp') ? 'webp' : ''));
        if($ext==='') throw new RuntimeException('Formato no permitido. Usá PNG/JPG/WEBP.');
        $name = $prefix.'_'.preg_replace('/[^a-z0-9_\-]/i','',$slug).'.'.$ext;
        $dst = $clientUploads . DIRECTORY_SEPARATOR . $name;
        if(!@move_uploaded_file($tmp, $dst)) throw new RuntimeException('No se pudo guardar la imagen.');
        return 'uploads/branding/'.$name;
      };

      $newLogo = $save_img('logo', 'logo');
      if($newLogo) $logo = $newLogo;
      $newCover = $save_img('cover', 'cover');
      if($newCover) $cover = $newCover;

      $pdo->prepare("UPDATE businesses SET name=:n, owner_email=:e, address=:a, maps_url=:m, whatsapp_phone=:w, instagram_url=:ig, intro_text=:it, timezone=:tz, theme_primary=:tp, theme_accent=:ta, logo_path=:lp, cover_path=:cp WHERE id=1")
          ->execute([
            ':n'=>trim($_POST['name']??''),
            ':e'=>trim($_POST['owner_email']??''),
            ':a'=>trim($_POST['address']??''),
            ':m'=>trim($_POST['maps_url']??''),
            ':w'=>trim($_POST['whatsapp_phone']??''),
            ':ig'=>trim($_POST['instagram_url']??''),
            ':it'=>trim($_POST['intro_text']??''),
            ':tz'=>trim($_POST['timezone']??'America/Argentina/Buenos_Aires'),
            ':tp'=>trim($_POST['theme_primary']??''),
            ':ta'=>trim($_POST['theme_accent']??''),
            ':lp'=>$logo,
            ':cp'=>$cover,
          ]);
      flash_set('ok','Negocio actualizado.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=business'); exit;
    }

    if($action==='save_smtp'){
      $pdo->prepare("UPDATE businesses SET smtp_host=:h, smtp_port=:p, smtp_user=:u, smtp_pass=:pw, smtp_from_email=:f, smtp_from_name=:fn, smtp_secure=:s, smtp_enabled=:en WHERE id=1")
          ->execute([
            ':h'=>trim($_POST['smtp_host']??''),
            ':p'=>intval($_POST['smtp_port']??0),
            ':u'=>trim($_POST['smtp_user']??''),
            ':pw'=>trim($_POST['smtp_pass']??''),
            ':f'=>trim($_POST['smtp_from_email']??''),
            ':fn'=>trim($_POST['smtp_from_name']??''),
            ':s'=>trim($_POST['smtp_secure']??''),
            ':en'=>(trim($_POST['smtp_host']??'')!=='' && trim($_POST['smtp_user']??'')!=='' && trim($_POST['smtp_pass']??'')!=='') ? 1 : 0,
          ]);
      flash_set('ok','SMTP actualizado.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=smtp'); exit;
    }

    if($action==='branch_add'){
      $pdo->prepare("INSERT INTO branches (business_id, name, address, whatsapp_phone, owner_email, maps_url) VALUES (1,:n,:a,:w,:e,:m)")
          ->execute([
            ':n'=>trim($_POST['name']??'Sucursal'),
            ':a'=>trim($_POST['address']??''),
            ':w'=>trim($_POST['whatsapp_phone']??''),
            ':e'=>trim($_POST['owner_email']??''),
            ':m'=>trim($_POST['maps_url']??''),
          ]);
      flash_set('ok','Sucursal creada.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=branches'); exit;
    }
    if($action==='branch_del'){
      $id=intval($_POST['id']??0);
      $pdo->prepare("DELETE FROM branches WHERE business_id=1 AND id=:id")->execute([':id'=>$id]);
      flash_set('ok','Sucursal eliminada.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=branches'); exit;
    }

    if($action==='service_add'){
      $pdo->prepare("INSERT INTO services (business_id, name, description, duration_minutes, price_ars, image_url, is_active) VALUES (1,:n,:d,:dur,:pr,:img,1)")
          ->execute([
            ':n'=>trim($_POST['name']??'Servicio'),
            ':d'=>trim($_POST['description']??''),
            ':dur'=>intval($_POST['duration_minutes']??30),
            ':pr'=>intval($_POST['price_ars']??0),
            ':img'=>trim($_POST['image_url']??''),
          ]);
      flash_set('ok','Servicio creado.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=services'); exit;
    }
    if($action==='service_del'){
      $id=intval($_POST['id']??0);
      $pdo->prepare("DELETE FROM services WHERE business_id=1 AND id=:id")->execute([':id'=>$id]);
      flash_set('ok','Servicio eliminado.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=services'); exit;
    }

    if($action==='barber_add'){
      $pdo->prepare("INSERT INTO barbers (business_id, branch_id, name, capacity, is_active) VALUES (1,:b,:n,:c,1)")
          ->execute([
            ':b'=>intval($_POST['branch_id']??1),
            ':n'=>trim($_POST['name']??'Profesional'),
            ':c'=>intval($_POST['capacity']??1),
          ]);
      flash_set('ok','Profesional creado.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=barbers'); exit;
    }
    if($action==='barber_del'){
      $id=intval($_POST['id']??0);
      $pdo->prepare("DELETE FROM barbers WHERE business_id=1 AND id=:id")->execute([':id'=>$id]);
      flash_set('ok','Profesional eliminado.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=barbers'); exit;
    }

    if($action==='user_add'){
      $hash = password_hash((string)($_POST['password']??''), PASSWORD_DEFAULT);
      $pdo->prepare("INSERT INTO users (business_id, username, password_hash, role) VALUES (1,:u,:p,:r)")
          ->execute([
            ':u'=>trim($_POST['username']??''),
            ':p'=>$hash,
            ':r'=>trim($_POST['role']??'admin'),
          ]);
      flash_set('ok','Usuario creado.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=users'); exit;
    }
    if($action==='user_reset'){
      $id=intval($_POST['id']??0);
      $hash = password_hash((string)($_POST['password']??''), PASSWORD_DEFAULT);
      $pdo->prepare("UPDATE users SET password_hash=:p WHERE business_id=1 AND id=:id")->execute([':p'=>$hash, ':id'=>$id]);
      flash_set('ok','Contraseña actualizada.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=users'); exit;
    }
    if($action==='user_del'){
      $id=intval($_POST['id']??0);
      $pdo->prepare("DELETE FROM users WHERE business_id=1 AND id=:id")->execute([':id'=>$id]);
      flash_set('ok','Usuario eliminado.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=users'); exit;
    }

    if($action==='save_hours'){
      $branch=intval($_POST['branch_id']??1);
      $pdo->prepare("DELETE FROM business_hours WHERE business_id=1 AND branch_id=:b")->execute([':b'=>$branch]);
      for($wd=0;$wd<=6;$wd++){
        $closed = isset($_POST['closed'][$wd]) ? 1 : 0;
        $open = trim($_POST['open'][$wd] ?? '');
        $close = trim($_POST['close'][$wd] ?? '');
        $pdo->prepare("INSERT INTO business_hours (business_id, branch_id, weekday, open_time, close_time, is_closed) VALUES (1,:b,:w,:o,:c,:cl)")
            ->execute([':b'=>$branch,':w'=>$wd,':o'=>$open,':c'=>$close,':cl'=>$closed]);
      }
      flash_set('ok','Horarios guardados.');
      header('Location: manage.php?c='.urlencode($slug).'&tab=hours'); exit;
    }

    if($action==='toggle_disable'){
      $flag = client_dir($slug).DIRECTORY_SEPARATOR.'.disabled';
      if(file_exists($flag)){ unlink($flag); flash_set('ok','Cliente activado.'); }
      else { file_put_contents($flag, 'disabled'); flash_set('ok','Cliente desactivado.'); }
      header('Location: manage.php?c='.urlencode($slug).'&tab=ops'); exit;
    }

    if($action==='backup'){
      $clientPath = client_dir($slug);
      $backupDir = __DIR__.DIRECTORY_SEPARATOR.'backups';
      if(!is_dir($backupDir)) mkdir($backupDir,0777,true);
      $name = $slug.'_'.date('Ymd_His').'.zip';
      $zipPath = $backupDir.DIRECTORY_SEPARATOR.$name;

      $zip = new ZipArchive();
      if($zip->open($zipPath, ZipArchive::CREATE)!==TRUE) throw new RuntimeException('No se pudo crear ZIP');
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($clientPath, FilesystemIterator::SKIP_DOTS));
      foreach($it as $file){
        $fp = $file->getPathname();
        $rel = substr($fp, strlen($clientPath)+1);
        $zip->addFile($fp, $slug.'/'.$rel);
      }
      $zip->close();
      flash_set('ok','Backup creado: '.$name);
      header('Location: manage.php?c='.urlencode($slug).'&tab=ops'); exit;
    }

    if($action==='delete_client'){
      $confirm = trim($_POST['confirm'] ?? '');
      if($confirm !== $slug) throw new RuntimeException('Confirmación incorrecta.');
      // delete dir recursively
      $dir = client_dir($slug);
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
      foreach($it as $f){
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
      }
      rmdir($dir);
      flash_set('ok','Cliente eliminado.');
      header('Location: dashboard.php'); exit;
    }

  } catch(Throwable $e){
    flash_set('err','Error: '.$e->getMessage());
    header('Location: manage.php?c='.urlencode($slug).'&tab='.urlencode($tab)); exit;
  }
}

$biz = $pdo->query("SELECT * FROM businesses WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$branches = $pdo->query("SELECT id,name FROM branches WHERE business_id=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

function tab_link($slug,$tab,$label,$active){
  $cls = $active ? 'tab active' : 'tab';
  echo '<a class="'.$cls.'" href="manage.php?c='.urlencode($slug).'&tab='.urlencode($tab).'">'.h($label).'</a>';
}

header_html('Gestionar: '.$slug);
?>
<div class="card" style="margin-bottom:12px">
  <div class="tabs">
    <?php
      tab_link($slug,'business','Negocio', $tab==='business');
      tab_link($slug,'smtp','SMTP', $tab==='smtp');
      tab_link($slug,'branches','Sucursales', $tab==='branches');
      tab_link($slug,'services','Servicios', $tab==='services');
      tab_link($slug,'barbers','Profesionales', $tab==='barbers');
      tab_link($slug,'hours','Horarios', $tab==='hours');
      tab_link($slug,'users','Usuarios', $tab==='users');
      tab_link($slug,'ops','Operaciones', $tab==='ops');
    ?>
  </div>
  <div class="small">Todo se administra desde acá (Super Admin). El dueño del local no ve estas pantallas técnicas.</div>
</div>

<?php if($tab==='business'): ?>
<div class="card">
  <h3 style="margin:0 0 10px 0;">Datos del negocio</h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save_business">
    <div class="grid">
      <div class="col-6"><label>Nombre</label><input name="name" value="<?=h($biz['name']??'')?>"></div>
      <div class="col-6"><label>Email dueño</label><input name="owner_email" value="<?=h($biz['owner_email']??'')?>"></div>
      <div class="col-12"><label>Dirección</label><input name="address" value="<?=h($biz['address']??'')?>"></div>
      <div class="col-12"><label>Link Google Maps</label><input name="maps_url" value="<?=h($biz['maps_url']??'')?>"></div>
      <div class="col-6"><label>WhatsApp</label><input name="whatsapp_phone" value="<?=h($biz['whatsapp_phone']??'')?>"></div>
      <div class="col-6"><label>Instagram URL</label><input name="instagram_url" value="<?=h($biz['instagram_url']??'')?>"></div>
      <div class="col-12"><label>Texto de bienvenida</label><textarea name="intro_text"><?=h($biz['intro_text']??'')?></textarea></div>
      <div class="col-6"><label>Color principal (hex)</label><input name="theme_primary" value="<?=h($biz['theme_primary']??'')?>" placeholder="#2563eb"></div>
      <div class="col-6"><label>Color acento (hex)</label><input name="theme_accent" value="<?=h($biz['theme_accent']??'')?>" placeholder="#2563eb"></div>
      
      <div class="col-12">
        <label>Branding actual</label>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
          <?php if(!empty($biz['logo_path'])): ?>
            <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start">
              <img src="../<?=h($slug)?>/public/<?=h($biz['logo_path'])?>" style="width:72px;height:72px;object-fit:cover;border-radius:12px;border:1px solid #e5e7eb" alt="logo">
              <span class="small"><?=h($biz['logo_path'])?></span>
            </div>
          <?php endif; ?>
          <?php if(!empty($biz['cover_path'])): ?>
            <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start">
              <img src="../<?=h($slug)?>/public/<?=h($biz['cover_path'])?>" style="width:180px;height:72px;object-fit:cover;border-radius:12px;border:1px solid #e5e7eb" alt="cover">
              <span class="small"><?=h($biz['cover_path'])?></span>
            </div>
          <?php endif; ?>
          <?php if(empty($biz['logo_path']) && empty($biz['cover_path'])): ?>
            <span class="small">Sin logo/portada cargados.</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-6"><label>Logo (PNG/JPG/WEBP)</label><input type="file" name="logo" accept="image/*"></div>
      <div class="col-6"><label>Portada (PNG/JPG/WEBP)</label><input type="file" name="cover" accept="image/*"></div>
      <div class="col-6"><label>Timezone</label><input name="timezone" value="<?=h($biz['timezone']??'America/Argentina/Buenos_Aires')?>"></div>
    </div>
    <div style="margin-top:12px"><button class="btn btn-primary">Guardar</button></div>
  </form>
</div>
<?php endif; ?>

<?php if($tab==='smtp'): ?>
<div class="card">
  <h3 style="margin:0 0 10px 0;">SMTP</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save_smtp">
    <div class="grid">
      <div class="col-6"><label>Host</label><input name="smtp_host" value="<?=h($biz['smtp_host']??'')?>"></div>
      <div class="col-3"><label>Port</label><input name="smtp_port" value="<?=h($biz['smtp_port']??'')?>"></div>
      <div class="col-3"><label>Secure (tls/ssl)</label><input name="smtp_secure" value="<?=h($biz['smtp_secure']??'')?>"></div>
      <div class="col-6"><label>User</label><input name="smtp_user" value="<?=h($biz['smtp_user']??'')?>"></div>
      <div class="col-6"><label>Pass</label><input name="smtp_pass" value="<?=h($biz['smtp_pass']??'')?>"></div>
      <div class="col-6"><label>From Email</label><input name="smtp_from_email" value="<?=h($biz['smtp_from_email']??'')?>"></div>
      <div class="col-6"><label>From Name</label><input name="smtp_from_name" value="<?=h($biz['smtp_from_name']??'')?>"></div>
    </div>
    <div style="margin-top:12px"><button class="btn btn-primary">Guardar</button></div>
  </form>
</div>
<?php endif; ?>

<?php if($tab==='branches'): 
$rows = $pdo->query("SELECT * FROM branches WHERE business_id=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="grid">
  <div class="col-8">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Sucursales</h3>
      <table>
        <thead><tr><th>ID</th><th>Nombre</th><th>Dirección</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['id'])?></td>
              <td><?=h($r['name'])?></td>
              <td><?=h($r['address'])?></td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="branch_del">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <button class="btn btn-danger" onclick="return confirm('¿Eliminar sucursal?')">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-4">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Agregar</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="branch_add">
        <div style="margin-bottom:10px"><label>Nombre</label><input name="name" required></div>
        <div style="margin-bottom:10px"><label>Dirección</label><input name="address"></div>
        <div style="margin-bottom:10px"><label>WhatsApp</label><input name="whatsapp_phone"></div>
        <div style="margin-bottom:10px"><label>Email</label><input name="owner_email"></div>
        <div style="margin-bottom:12px"><label>Maps URL</label><input name="maps_url"></div>
        <button class="btn btn-primary">Crear</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if($tab==='services'):
$rows = $pdo->query("SELECT id,name,duration_minutes,price_ars,is_active FROM services WHERE business_id=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="grid">
  <div class="col-8">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Servicios</h3>
      <table>
        <thead><tr><th>ID</th><th>Nombre</th><th>Duración</th><th>Precio</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['id'])?></td>
              <td><?=h($r['name'])?></td>
              <td><?=h($r['duration_minutes'])?> min</td>
              <td>$<?=h($r['price_ars'])?></td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="service_del">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <button class="btn btn-danger" onclick="return confirm('¿Eliminar servicio?')">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-4">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Agregar</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="service_add">
        <div style="margin-bottom:10px"><label>Nombre</label><input name="name" required></div>
        <div style="margin-bottom:10px"><label>Duración (min)</label><input name="duration_minutes" value="30"></div>
        <div style="margin-bottom:10px"><label>Precio ARS</label><input name="price_ars" value="0"></div>
        <div style="margin-bottom:12px"><label>Descripción</label><textarea name="description" rows="3"></textarea></div>
        <button class="btn btn-primary">Crear</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if($tab==='barbers'):
$rows = $pdo->query("SELECT b.id,b.name,b.branch_id,b.is_active,b.capacity,br.name as branch_name FROM barbers b JOIN branches br ON br.id=b.branch_id WHERE b.business_id=1 ORDER BY b.id")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="grid">
  <div class="col-8">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Profesionales</h3>
      <table>
        <thead><tr><th>ID</th><th>Nombre</th><th>Sucursal</th><th>Cap.</th><th>Activo</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['id'])?></td>
              <td><?=h($r['name'])?></td>
              <td><?=h($r['branch_name'])?></td>
              <td><?=h($r['capacity'])?></td>
              <td><?= $r['is_active']?'<span class="badge badge-on">Sí</span>':'<span class="badge badge-off">No</span>' ?></td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="barber_del">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <button class="btn btn-danger" onclick="return confirm('¿Eliminar profesional?')">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-4">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Agregar</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="barber_add">
        <div style="margin-bottom:10px"><label>Nombre</label><input name="name" required></div>
        <div style="margin-bottom:10px"><label>Sucursal</label>
          <select name="branch_id">
            <?php foreach($branches as $b): ?>
              <option value="<?=h($b['id'])?>"><?=h($b['name'])?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div style="margin-bottom:12px"><label>Capacidad</label><input name="capacity" value="1"></div>
        <button class="btn btn-primary">Crear</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if($tab==='hours'):
$branchId = (int)($branches[0]['id'] ?? 1);
$rows = $pdo->query("SELECT weekday,open_time,close_time,is_closed FROM business_hours WHERE business_id=1 AND branch_id=".(int)$branchId." ORDER BY weekday")->fetchAll(PDO::FETCH_ASSOC);
$map = [];
foreach($rows as $r){ $map[(int)$r['weekday']]=$r; }
$days = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
?>
<div class="card">
  <h3 style="margin:0 0 10px 0;">Horarios (Sucursal <?=h($branchId)?>)</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save_hours">
    <input type="hidden" name="branch_id" value="<?=h($branchId)?>">
    <table>
      <thead><tr><th>Día</th><th>Abre</th><th>Cierra</th><th>Cerrado</th></tr></thead>
      <tbody>
      <?php for($wd=0;$wd<=6;$wd++):
        $r=$map[$wd] ?? ['open_time'=>'09:00','close_time'=>'19:00','is_closed'=>($wd===0?1:0)];
      ?>
        <tr>
          <td><?=h($days[$wd])?></td>
          <td><input name="open[<?=h($wd)?>]" value="<?=h($r['open_time']??'')?>"></td>
          <td><input name="close[<?=h($wd)?>]" value="<?=h($r['close_time']??'')?>"></td>
          <td style="text-align:center">
            <input type="checkbox" name="closed[<?=h($wd)?>]" <?= ((int)($r['is_closed']??0)===1?'checked':'') ?>>
          </td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    <div style="margin-top:12px"><button class="btn btn-primary">Guardar</button></div>
  </form>
</div>
<?php endif; ?>

<?php if($tab==='users'):
$rows = $pdo->query("SELECT id,username,role,created_at FROM users WHERE business_id=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="grid">
  <div class="col-8">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Usuarios</h3>
      <table>
        <thead><tr><th>ID</th><th>Usuario</th><th>Rol</th><th>Creado</th><th>Reset</th><th>Eliminar</th></tr></thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['id'])?></td>
              <td><?=h($r['username'])?></td>
              <td><?=h($r['role'])?></td>
              <td><?=h($r['created_at'])?></td>
              <td>
                <form method="post" style="display:flex;gap:8px;align-items:center">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="user_reset">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <input name="password" type="password" placeholder="Nueva pass" required style="max-width:180px">
                  <button class="btn">Guardar</button>
                </form>
              </td>
              <td>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="user_del">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <button class="btn btn-danger" onclick="return confirm('¿Eliminar usuario?')">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-4">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Agregar</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="user_add">
        <div style="margin-bottom:10px"><label>Usuario</label><input name="username" required></div>
        <div style="margin-bottom:10px"><label>Contraseña</label><input name="password" type="password" required></div>
        <div style="margin-bottom:12px"><label>Rol</label>
          <select name="role">
            <option value="admin">admin</option>
            <option value="staff">staff</option>
          </select>
        </div>
        <button class="btn btn-primary">Crear</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if($tab==='ops'): ?>
<div class="grid">
  <div class="col-6">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Operaciones</h3>
      <form method="post" style="margin-bottom:10px">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="toggle_disable">
        <button class="btn"><?= client_disabled($slug) ? 'Activar cliente' : 'Desactivar cliente' ?></button>
      </form>
      <form method="post" style="margin-bottom:10px">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="backup">
        <button class="btn">Crear backup ZIP</button>
        <div class="small" style="margin-top:6px">Se guarda en <code>admin/backups/</code></div>
      </form>
      <?php
        $backupDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
        $bfiles = [];
        if (is_dir($backupDir)) {
          $bfiles = glob($backupDir . DIRECTORY_SEPARATOR . $slug . '_*.zip') ?: [];
          rsort($bfiles);
        }
      ?>
      <div style="margin-top:10px">
        <div class="small" style="margin-bottom:6px">Backups existentes</div>
        <?php if(empty($bfiles)): ?>
          <div class="small muted">Todavía no hay backups.</div>
        <?php else: ?>
          <table class="table compact">
            <thead><tr><th>Archivo</th><th>Tamaño</th><th></th></tr></thead>
            <tbody>
            <?php foreach($bfiles as $fp): $fn = basename($fp); ?>
              <tr>
                <td><code><?=h($fn)?></code></td>
                <td><?=h(number_format(filesize($fp)/1024/1024, 2))?> MB</td>
                <td><a class="btn" href="download_backup.php?c=<?=h(urlencode($slug))?>&f=<?=h(urlencode($fn))?>">Descargar</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <div class="small">Links rápidos:</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
        <a class="btn" target="_blank" href="../<?=h($slug)?>/public/">Abrir sitio</a>
        <a class="btn" target="_blank" href="../<?=h($slug)?>/admin/">Abrir admin</a>
      </div>
    </div>
  </div>
  <div class="col-6">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Eliminar cliente</h3>
      <div class="notice err">Esto borra toda la carpeta del cliente.</div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="delete_client">
        <label>Escribí el slug para confirmar</label>
        <input name="confirm" placeholder="<?=h($slug)?>" required>
        <div style="margin-top:12px">
          <button class="btn btn-danger" onclick="return confirm('Última confirmación: ¿borrar definitivamente?')">Eliminar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<?php footer_html(); ?>
