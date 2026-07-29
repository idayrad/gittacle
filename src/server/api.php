<?php
// server/api.php
// Simple PHP backend for MVP GitHub client.
// Endpoints (action query param):
// - save_credentials (POST JSON): { username, repo, branch, pat, global }
// - pull_repo (POST JSON): { owner, repo, branch }
// - list_files (GET): owner, repo, branch
// - get_file (GET): owner, repo, branch, path
// - create_file (POST JSON): owner, repo, branch, path, content, message
// - save_file (POST JSON): owner, repo, branch, path, content, message
// - push_file (POST JSON): same as save_file but forces push to GitHub
// - upload_files (multipart POST): files[] + owner, repo, branch
// - delete_files (POST JSON): owner, repo, branch, paths[]
// - download_zip (GET): owner, repo, branch
// - json_op (POST JSON): see user spec: either single file or files[]; supports append:true
//
// Notes: This is an MVP. For production, secure the DB, encrypt PATs, add authentication, CSRF protection, rate limiting, and error handling.
//
// Data layout: data/{owner}/{repo}/{branch}/... local copy of files
// SQLite DB: data/db.sqlite with table tokens (username, repo, pat, is_global)

header('Content-Type: application/json; charset=utf-8');
$basedir = __DIR__ . '/../data';
if(!is_dir($basedir)) mkdir($basedir, 0750, true);

// simple DB init
$dbfile = $basedir . '/db.sqlite';
$db = new PDO('sqlite:'.$dbfile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS tokens (id INTEGER PRIMARY KEY, username TEXT, repo TEXT, pat TEXT, is_global INTEGER)");

$action = $_GET['action'] ?? $_POST['action'] ?? null;

function json($d){ echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; }

function require_json_body(){
  $b = file_get_contents('php://input');
  $data = json_decode($b, true);
  if($data===null) json(['ok'=>false,'error'=>'invalid_json','raw'=>$b]);
  return $data;
}

// helper: use stored PAT first; supports global tokens
function get_pat_for($db, $username, $repo){
  // prefer repo-specific
  $stmt = $db->prepare("SELECT pat FROM tokens WHERE username=:username AND repo=:repo LIMIT 1");
  $stmt->execute([':username'=>$username,':repo'=>$repo]);
  $r = $stmt->fetch(PDO::FETCH_ASSOC);
  if($r) return $r['pat'];
  // try global token for username (repo = '*')
  $stmt = $db->prepare("SELECT pat FROM tokens WHERE username=:username AND is_global=1 LIMIT 1");
  $stmt->execute([':username'=>$username]);
  $r = $stmt->fetch(PDO::FETCH_ASSOC);
  return $r['pat'] ?? null;
}

function gh_api($method, $url, $pat=null, $data=null, $headers_add=[]){
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $headers = ["Accept: application/vnd.github+json", "User-Agent: gittacle-mvp"];
  if($pat) $headers[] = "Authorization: token $pat";
  foreach($headers_add as $h) $headers[] = $h;
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  if($method==='POST' || $method==='PUT' || $method==='PATCH' || $method==='DELETE'){
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if($data!==null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  }
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $res];
}

switch($action){
  case 'save_credentials':
    $data = require_json_body();
    $username = $data['username'] ?? null;
    $repo = $data['repo'] ?? null;
    $pat = $data['pat'] ?? null;
    $branch = $data['branch'] ?? 'main';
    $global = !empty($data['global']) ? 1 : 0;
    if(!$username || !$repo || !$pat) json(['ok'=>false,'error'=>'missing_fields']);
    // store token; naive: store string as is (production: encrypt!)
    $stmt = $db->prepare("INSERT INTO tokens (username,repo,pat,is_global) VALUES (:username,:repo,:pat,:is_global)");
    $stmt->execute([':username'=>$username,':repo'=>$repo,':pat'=>$pat,':is_global'=>$global]);
    json(['ok'=>true]);
    break;

  case 'pull_repo':
    $data = require_json_body();
    $owner = $data['owner']; $repo = $data['repo']; $branch = $data['branch'] ?? 'main';
    if(!$owner || !$repo) json(['ok'=>false,'error'=>'missing']);
    $pat = get_pat_for($db, $owner, $repo);
    if(!$pat) json(['ok'=>false,'error'=>'no_pat']);
    // use Git Trees API to list all files (recursive)
    // first, get the branch SHA
    list($code,$body) = gh_api('GET',"https://api.github.com/repos/$owner/$repo/git/refs/heads/$branch",$pat);
    if($code !== 200) json(['ok'=>false,'error'=>'ref_not_found','code'=>$code,'body'=>$body]);
    $ref = json_decode($body, true);
    $tree_sha = $ref['object']['sha'];
    // get tree recursively
    list($tc,$tb) = gh_api('GET',"https://api.github.com/repos/$owner/$repo/git/trees/$tree_sha?recursive=1",$pat);
    if($tc !== 200) json(['ok'=>false,'error'=>'tree_failed','code'=>$tc,'body'=>$tb]);
    $treedata = json_decode($tb, true);
    $files = $treedata['tree'] ?? [];
    // create local folder and save blobs for 'blob' types
    $localroot = "$basedir/$owner/$repo/$branch";
    if(!is_dir($localroot)) @mkdir($localroot, 0750, true);
    foreach($files as $item){
      if($item['type'] !== 'blob') continue;
      $path = $localroot . '/' . $item['path'];
      $dir = dirname($path);
      if(!is_dir($dir)) @mkdir($dir, 0750, true);
      // fetch blob
      list($bc,$bb) = gh_api('GET',"https://api.github.com/repos/$owner/$repo/git/blobs/{$item['sha']}", $pat);
      if($bc !== 200) continue;
      $bdat = json_decode($bb, true);
      $content = base64_decode($bdat['content']);
      file_put_contents($path, $content);
    }
    json(['ok'=>true]);
    break;

  case 'list_files':
    $owner = $_GET['owner'] ?? null; $repo = $_GET['repo'] ?? null; $branch = $_GET['branch'] ?? 'main';
    if(!$owner||!$repo) json(['ok'=>false,'error'=>'missing']);
    $localroot = "$basedir/$owner/$repo/$branch";
    $out = [];
    if(is_dir($localroot)){
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($localroot));
      foreach($it as $f){
        if($f->isFile()){
          $rel = substr($f->getPathname(), strlen($localroot)+1);
          $out[] = ['path'=>$rel, 'size'=>$f->getSize()];
        }
      }
    }
    json(['ok'=>true,'files'=>$out]);
    break;

  case 'get_file':
    $owner = $_GET['owner'] ?? null; $repo = $_GET['repo'] ?? null; $branch = $_GET['branch'] ?? 'main'; $path = $_GET['path'] ?? null;
    if(!$owner||!$repo||!$path) json(['ok'=>false,'error'=>'missing']);
    $local = "$basedir/$owner/$repo/$branch/$path";
    if(!file_exists($local)) json(['ok'=>false,'error'=>'not_found']);
    $content = file_get_contents($local);
    json(['ok'=>true,'content'=>$content]);
    break;

  case 'create_file':
  case 'save_file':
    $data = require_json_body();
    $owner = $data['owner'] ?? null; $repo = $data['repo'] ?? null; $branch = $data['branch'] ?? 'main';
    $path = $data['path'] ?? null; $content = $data['content'] ?? ''; $message = $data['message'] ?? "Update $path";
    if(!$owner||!$repo||!$path) json(['ok'=>false,'error'=>'missing']);
    $local = "$basedir/$owner/$repo/$branch/$path";
    $dir = dirname($local);
    if(!is_dir($dir)) @mkdir($dir, 0750, true);
    file_put_contents($local, $content);
    // do not push to GitHub here (separate push endpoint); server keeps local copy
    json(['ok'=>true]);
    break;

  case 'push_file':
    // Push single file to GitHub using Contents API (create/update)
    $data = require_json_body();
    $owner = $data['owner'] ?? null; $repo = $data['repo'] ?? null; $branch = $data['branch'] ?? 'main';
    $path = $data['path'] ?? null; $content = $data['content'] ?? ''; $message = $data['message'] ?? "Update $path";
    if(!$owner||!$repo||!$path) json(['ok'=>false,'error'=>'missing']);
    $pat = get_pat_for($db, $owner, $repo);
    if(!$pat) json(['ok'=>false,'error'=>'no_pat']);
    // check if exists to get sha
    list($hc,$hb) = gh_api('GET', "https://api.github.com/repos/$owner/$repo/contents/".rawurlencode($path)."?ref=".rawurlencode($branch), $pat);
    $payload = ['message'=>$message,'content'=>base64_encode($content),'branch'=>$branch];
    if($hc===200){
      $j = json_decode($hb,true); $payload['sha'] = $j['sha'];
    }
    list($pc,$pb) = gh_api('PUT', "https://api.github.com/repos/$owner/$repo/contents/".rawurlencode($path), $pat, $payload);
    if($pc>=200 && $pc<300) json(['ok'=>true,'result'=>json_decode($pb,true)]);
    json(['ok'=>false,'error'=>'push_failed','code'=>$pc,'body'=>$pb]);
    break;

  case 'upload_files':
    // multipart upload: store in local copy and optionally push later
    // expected form fields: owner, repo, branch and files[]
    $owner = $_POST['owner'] ?? null; $repo = $_POST['repo'] ?? null; $branch = $_POST['branch'] ?? 'main';
    if(!$owner||!$repo) json(['ok'=>false,'error'=>'missing']);
    $localroot = "$basedir/$owner/$repo/$branch";
    if(!is_dir($localroot)) @mkdir($localroot, 0750, true);
    $saved = [];
    foreach($_FILES['files']['tmp_name'] as $i => $tmp){
      $name = basename($_FILES['files']['name'][$i]);
      $dest = $localroot.'/'.$name;
      move_uploaded_file($tmp,$dest);
      $saved[] = $name;
    }
    json(['ok'=>true,'saved'=>$saved]);
    break;

  case 'delete_files':
    $data = require_json_body();
    $owner = $data['owner'] ?? null; $repo = $data['repo'] ?? null; $branch = $data['branch'] ?? 'main';
    $paths = $data['paths'] ?? [];
    if(!$owner||!$repo) json(['ok'=>false,'error'=>'missing']);
    $results = [];
    foreach($paths as $p){
      $local = "$basedir/$owner/$repo/$branch/$p";
      if(file_exists($local)) { unlink($local); $results[$p]='deleted'; } else $results[$p]='not_found';
    }
    json(['ok'=>true,'results'=>$results]);
    break;

  case 'download_zip':
    $owner = $_GET['owner'] ?? null; $repo = $_GET['repo'] ?? null; $branch = $_GET['branch'] ?? 'main';
    if(!$owner||!$repo) { http_response_code(400); echo "missing"; exit; }
    $localroot = "$basedir/$owner/$repo/$branch";
    if(!is_dir($localroot)){ http_response_code(404); echo "not found"; exit; }
    $zipname = tempnam(sys_get_temp_dir(), 'gittacle').'.zip';
    $zip = new ZipArchive();
    if($zip->open($zipname, ZipArchive::CREATE)!==TRUE){ http_response_code(500); echo "zipfail"; exit; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($localroot));
    foreach($it as $f){
      if($f->isFile()){
        $rel = substr($f->getPathname(), strlen($localroot)+1);
        $zip->addFile($f->getPathname(), $rel);
      }
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.basename("$repo-$branch.zip").'"');
    readfile($zipname);
    unlink($zipname);
    exit;
    break;

  case 'json_op':
    // handle the structured JSON operations described by the user.
    // Sample single-file:
    // { owner, repo, branch, path, message, content }
    // Or multi-file:
    // { owner, repo, branch, message, files: [ {path,content} ] }
    // append: true/false (default false)
    $data = require_json_body();
    $owner = $data['owner'] ?? null; $repo = $data['repo'] ?? null; $branch = $data['branch'] ?? 'main';
    if(!$owner||!$repo) json(['ok'=>false,'error'=>'missing']);
    $pat = get_pat_for($db, $owner, $repo); if(!$pat) json(['ok'=>false,'error'=>'no_pat']);
    // validate repo exists
    list($rc,$rb) = gh_api('GET', "https://api.github.com/repos/$owner/$repo", $pat);
    if($rc!==200) json(['ok'=>false,'error'=>'repo_not_found','code'=>$rc,'body'=>$rb]);
    $message = $data['message'] ?? 'Update files';
    $append = !empty($data['append']);
    $files = [];
    if(isset($data['files']) && is_array($data['files'])) $files = $data['files'];
    elseif(isset($data['path'])) $files[] = ['path'=>$data['path'],'content'=>$data['content'] ?? ''];
    else json(['ok'=>false,'error'=>'no_files']);

    $out = [];
    foreach($files as $f){
      $path = $f['path']; $contents = $f['content'] ?? '';
      // if append, fetch existing content first
      if($append){
        list($hc,$hb) = gh_api('GET', "https://api.github.com/repos/$owner/$repo/contents/".rawurlencode($path)."?ref=".rawurlencode($branch), $pat);
        if($hc===200){
          $j = json_decode($hb,true);
          $existing = base64_decode($j['content']);
          $contents = $existing . $contents;
        } else {
          // create new if not exists (append semantics: create if missing)
        }
      }
      // create/update via contents API
      // get sha if exists
      list($hc2,$hb2) = gh_api('GET', "https://api.github.com/repos/$owner/$repo/contents/".rawurlencode($path)."?ref=".rawurlencode($branch), $pat);
      $payload = ['message'=>$message, 'content'=>base64_encode($contents), 'branch'=>$branch];
      if($hc2===200){ $j2=json_decode($hb2,true); $payload['sha'] = $j2['sha']; }
      list($pc,$pb) = gh_api('PUT', "https://api.github.com/repos/$owner/$repo/contents/".rawurlencode($path), $pat, $payload);
      if($pc>=200 && $pc<300){ $out[$path]=['ok'=>true]; } else { $out[$path]=['ok'=>false,'code'=>$pc,'body'=>$pb]; }
      // update local copy if present
      $local = "$basedir/$owner/$repo/$branch/$path";
      $dir = dirname($local); if(!is_dir($dir)) @mkdir($dir,0750,true);
      file_put_contents($local, $contents);
    }
    // after ops, respond and client should refresh
    json(['ok'=>true,'results'=>$out]);
    break;

  default:
    json(['ok'=>false,'error'=>'unknown_action:'.$action]);
}
