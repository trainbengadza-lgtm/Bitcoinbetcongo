<?php
declare(strict_types=1);

/* ============================================================
   BITCOINBET - VERSION POSTGRESQL / NEON - Prêt pour Netlify
   Base déjà créée sur Neon - plus besoin de CREATE DATABASE
   Auteur: Converti pour Brazzaville
   ============================================================ */

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $defaultUrl = 'postgresql://neondb_owner:npg_cI8Q7lRZiJgq@ep-young-hall-a5ifhxct-pooler.c-7.us-east-2.aws.neon.tech/neondb?sslmode=require';
    $databaseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: $defaultUrl;
    $parts = parse_url($databaseUrl);
    $host = $parts['host'] ?? 'localhost';
    $port = $parts['port'] ?? 5432;
    $user = $parts['user'] ?? '';
    $pass = $parts['pass'] ?? '';
    $dbname = ltrim($parts['path'] ?? '/neondb', '/');
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    return $pdo;
}

function initDatabase(PDO $pdo): void {
    try {
        $pdo->exec("CREATE EXTENSION IF NOT EXISTS pgcrypto");
        $q=$pdo->prepare('SELECT id FROM admin_users WHERE username=? LIMIT 1');
        $q->execute(['admin']);
        if (!$q->fetch()) {
            $q=$pdo->prepare("INSERT INTO admin_users(username,password_hash,is_active) VALUES(?, crypt(?, gen_salt('bf')), true) ON CONFLICT (username) DO NOTHING");
            $q->execute(['admin', '@rlere']);
        }
    } catch (Throwable $e) {}
}

session_set_cookie_params([
    'httponly'=>true,
    'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite'=>'Lax'
]);
session_start();
$pdo = db();
initDatabase($pdo);
$boot=[];
if(isset($_SESSION['user_id'])){
    $q=$pdo->prepare('SELECT id,balance FROM users WHERE id=? AND is_active=true');
    $q->execute([$_SESSION['user_id']]);
    $u=$q->fetch();
    if($u){
        $boot['bitcoinBetCurrentUser']=$u['id'];
        $boot['bitcoinBetBalance']=(string)$u['balance'];
    } else { unset($_SESSION['user_id']); }
}
if(!empty($_SESSION['admin_id'])) $boot['bitcoinBetAdminSession']='1';

if (isset($_GET['action'])) {
header('Content-Type: application/json; charset=utf-8');
function out(array $data, int $status=200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body(): array { $raw=file_get_contents('php://input'); $j=json_decode($raw ?: '{}', true); return is_array($j)?$j:[]; }
function uid(): ?string { return $_SESSION['user_id'] ?? null; }
function admin(): bool { return !empty($_SESSION['admin_id']); }
function getAdmin(PDO $pdo, string $username): ?array { $q=$pdo->prepare('SELECT id,username,password_hash,is_active FROM admin_users WHERE username=? LIMIT 1'); $q->execute([$username]); return $q->fetch() ?: null; }
function requireUser(): string { $id=uid(); if(!$id) out(['ok'=>false,'error'=>'Connexion requise.'],401); return $id; }
function safeUser(array $u): array { unset($u['password_hash']); return $u; }
function makeUser(PDO $pdo, array $d): array {
    $name=trim((string)($d['name']??'')); $username=strtolower(trim((string)($d['username']??'')));
    $phone=trim((string)($d['phone']??'')); $password=(string)($d['password']??'');
    if(strlen($name)<2||strlen($username)<3||$phone===''||strlen($password)<4) out(['ok'=>false,'error'=>'Données d’inscription invalides.'],422);
    if(!preg_match('/^[a-z0-9_.-]+$/i',$username)) out(['ok'=>false,'error'=>'Nom d’utilisateur invalide.'],422);
    $q=$pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');$q->execute([$username]);
    if($q->fetch()) out(['ok'=>false,'error'=>'Ce nom d’utilisateur existe déjà.'],409);
    $id='USR-'.strtoupper(base_convert((string)time(),10,36)).'-'.strtoupper(bin2hex(random_bytes(3)));
    $hash=password_hash($password,PASSWORD_DEFAULT);
    $q=$pdo->prepare('INSERT INTO users(id,name,username,phone,password_hash,balance) VALUES(?,?,?,?,?,10000)');
    $q->execute([$id,$name,$username,$phone,$hash]);
    $q=$pdo->prepare('SELECT id,name,username,phone,balance,created_at FROM users WHERE id=?');$q->execute([$id]);
    return $q->fetch();
}
function getUser(PDO $pdo,string $id): ?array { $q=$pdo->prepare('SELECT id,name,username,phone,balance,created_at,updated_at,is_active FROM users WHERE id=?'); $q->execute([$id]); return $q->fetch() ?: null; }
function storageGet(PDO $pdo,string $key): ?string {
    $user=uid();
    if($key==='bitcoinBetCurrentUser') return $user;
    if($key==='bitcoinBetBalance' && $user){$u=getUser($pdo,$user);return $u?(string)$u['balance']:null;}
    if(str_starts_with($key,'bitcoinBetTickets:')){
        if(!$user) return '[]'; $wanted=substr($key,17); if($wanted!==$user) return '[]';
        $q=$pdo->prepare('SELECT id,stake,total_odds AS totalOdds,potential_gain AS potentialGain,status,created_at,selections_json FROM tickets WHERE user_id=? ORDER BY created_at DESC');
        $q->execute([$user]);$a=[]; foreach($q as $r){$a[]=['id'=>$r['id'],'date'=>$r['created_at'],'userId'=>$user,'stake'=>(float)$r['stake'],'totalOdds'=>(float)$r['totalOdds'],'potentialGain'=>(float)$r['potentialGain'],'status'=>$r['status'],'selections'=>json_decode($r['selections_json'],true)?:[]];}
        return json_encode($a,JSON_UNESCAPED_UNICODE);
    }
    if($key==='bitcoinBetUsers'){
        $a=[]; if(admin()) { foreach($pdo->query('SELECT id,name,username,phone,balance,created_at AS createdAt,is_active FROM users ORDER BY created_at DESC') as $r)$a[]=$r; }
        elseif($user) { $u=getUser($pdo,$user); if($u) $a[]=['id'=>$u['id'],'name'=>$u['name'],'username'=>$u['username'],'phone'=>$u['phone'],'balance'=>(float)$u['balance'],'createdAt'=>$u['created_at'],'is_active'=>$u['is_active']]; }
        return json_encode($a,JSON_UNESCAPED_UNICODE);
    }
    if($key==='bitcoinBetWalletRequests'){
        $a=[]; if(admin()) { $q=$pdo->query('SELECT wr.*,u.username AS user FROM wallet_requests wr JOIN users u ON u.id=wr.user_id ORDER BY wr.created_at DESC'); }
        elseif($user) { $q=$pdo->prepare('SELECT wr.*,u.username AS user FROM wallet_requests wr JOIN users u ON u.id=wr.user_id WHERE wr.user_id=? ORDER BY wr.created_at DESC'); $q->execute([$user]); } else return '[]';
        foreach($q as $r){$a[]=['id'=>$r['id'],'userId'=>$r['user_id'],'user'=>$r['user'],'type'=>$r['type'],'amount'=>(float)$r['amount'],'operator'=>$r['operator'],'phone'=>$r['phone'],'status'=>$r['status'],'date'=>$r['created_at'],'processedAt'=>$r['processed_at']];}
        return json_encode($a,JSON_UNESCAPED_UNICODE);
    }
    if($key==='bitcoinBetAviatorWinnersV1'){
        $a=[];foreach($pdo->query("SELECT player_name AS name,gain,multiplier AS mult,round_number AS round,TO_CHAR(won_at,'HH24:MI:SS') AS time FROM aviator_winners ORDER BY id DESC LIMIT 30") as $r){$r['gain']=(float)$r['gain'];$r['mult']=(float)$r['mult'];$r['round']=(int)$r['round'];$a[]=$r;} return json_encode(array_reverse($a),JSON_UNESCAPED_UNICODE);
    }
    if($key==='bitcoinBetVirtualMatchesV2'){
        $a=[];foreach($pdo->query('SELECT * FROM virtual_matches ORDER BY id') as $r){
            $a[]=['id'=>$r['id'],'home'=>$r['home_team'],'away'=>$r['away_team'],'phase'=>$r['phase'],'remaining'=>(int)$r['remaining'],'elapsed'=>(int)$r['elapsed'],'homeScore'=>(int)$r['home_score'],'awayScore'=>(int)$r['away_score'],'shotsH'=>(int)$r['shots_home'],'shotsA'=>(int)$r['shots_away'],'attacksH'=>(int)$r['attacks_home'],'attacksA'=>(int)$r['attacks_away'],'cornersH'=>(int)$r['corners_home'],'cornersA'=>(int)$r['corners_away'],'possessionH'=>(float)$r['possession_home'],'possessionA'=>(float)$r['possession_away'],'lastEvent'=>$r['last_event'],'events'=>json_decode($r['events_json'],true)?:[],'odds'=>json_decode($r['odds_json'],true)?:[]];
        } return json_encode($a,JSON_UNESCAPED_UNICODE);
    }
    if($key==='bitcoinBetAdminSession') return admin()?'1':null;
    $q=$pdo->prepare('SELECT storage_value FROM app_storage WHERE storage_key=?');$q->execute([$key]);$r=$q->fetch();
    return $r['storage_value']??null;
}
function storageSet(PDO $pdo,string $key,?string $value,bool $remove=false): void {
    $user=uid();
    if($key==='bitcoinBetCurrentUser'){ return; }
    if($key==='bitcoinBetAdminSession'){ return; }
    if($key==='bitcoinBetBalance'){ $id=requireUser();$n=max(0,(float)$value);$q=$pdo->prepare('UPDATE users SET balance=? WHERE id=?');$q->execute([$n,$id]);return; }
    if(str_starts_with($key,'bitcoinBetTickets:')){
        $id=requireUser();$wanted=substr($key,17);if($wanted!==$id)return;
        $arr=json_decode($value?:'[]',true);if(!is_array($arr))$arr=[];
        $pdo->beginTransaction(); $pdo->prepare('DELETE FROM tickets WHERE user_id=?')->execute([$id]);
        $ins=$pdo->prepare('INSERT INTO tickets(id,user_id,stake,total_odds,potential_gain,status,selections_json) VALUES(?,?,?,?,?,?,?)');
        foreach($arr as $t){$ins->execute([(string)($t['id']??uniqid('BB-')),$id,(float)($t['stake']??0),(float)($t['totalOdds']??1),(float)($t['potentialGain']??0),(string)($t['status']??'En attente'),json_encode($t['selections']??[],JSON_UNESCAPED_UNICODE)]);}
        $pdo->commit();return;
    }
    if($key==='bitcoinBetUsers'){ if(!admin()) return; $arr=json_decode($value?:'[]',true);if(!is_array($arr))$arr=[]; foreach($arr as $u){ if(empty($u['id']))continue; $existing=getUser($pdo,(string)$u['id']); if($existing){ $q=$pdo->prepare('UPDATE users SET name=?,username=?,phone=?,balance=? WHERE id=?'); $q->execute([(string)($u['name']??$existing['name']),(string)($u['username']??$existing['username']),(string)($u['phone']??$existing['phone']),(float)($u['balance']??$existing['balance']),$u['id']]); } } return; }
    if($key==='bitcoinBetWalletRequests'){ return; }
    if($key==='bitcoinBetAviatorWinnersV1'){
        $arr=json_decode($value?:'[]',true);if(!is_array($arr))$arr=[]; foreach(array_slice($arr,-30) as $w){ $q=$pdo->prepare('SELECT id FROM aviator_winners WHERE round_number=? AND player_name=? AND gain=? LIMIT 1'); $q->execute([(int)($w['round']??0),(string)($w['name']??'Joueur'),(float)($w['gain']??0)); if(!$q->fetch()){$q=$pdo->prepare('INSERT INTO aviator_winners(player_name,gain,multiplier,round_number) VALUES(?,?,?,?)');$q->execute([(string)($w['name']??'Joueur'),(float)($w['gain']??0),(float)($w['mult']??0),(int)($w['round']??0)]);} } return;
    }
    if($key==='bitcoinBetVirtualMatchesV2'){
        $arr=json_decode($value?:'[]',true);if(!is_array($arr))$arr=[];
        $pdo->beginTransaction();
        $up=$pdo->prepare('INSERT INTO virtual_matches(id,home_team,away_team,phase,remaining,elapsed,home_score,away_score,shots_home,shots_away,attacks_home,attacks_away,corners_home,corners_away,possession_home,possession_away,last_event,events_json,odds_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT (id) DO UPDATE SET home_team=EXCLUDED.home_team,away_team=EXCLUDED.away_team,phase=EXCLUDED.phase,remaining=EXCLUDED.remaining,elapsed=EXCLUDED.elapsed,home_score=EXCLUDED.home_score,away_score=EXCLUDED.away_score,shots_home=EXCLUDED.shots_home,shots_away=EXCLUDED.shots_away,attacks_home=EXCLUDED.attacks_home,attacks_away=EXCLUDED.attacks_away,corners_home=EXCLUDED.corners_home,corners_away=EXCLUDED.corners_away,possession_home=EXCLUDED.possession_home,possession_away=EXCLUDED.possession_away,last_event=EXCLUDED.last_event,events_json=EXCLUDED.events_json,odds_json=EXCLUDED.odds_json,updated_at=NOW()');
        foreach($arr as $m)$up->execute([(string)$m['id'],(string)$m['home'],(string)$m['away'],(string)$m['phase'],(int)$m['remaining'],(int)$m['elapsed'],(int)$m['homeScore'],(int)$m['awayScore'],(int)$m['shotsH'],(int)$m['shotsA'],(int)$m['attacksH'],(int)$m['attacksA'],(int)$m['cornersH'],(int)$m['cornersA'],(float)$m['possessionH'],(float)$m['possessionA'],(string)($m['lastEvent']??''),json_encode($m['events']??[],JSON_UNESCAPED_UNICODE),json_encode($m['odds']??[],JSON_UNESCAPED_UNICODE)]);
        $pdo->commit();return;
    }
    if($remove){ $pdo->prepare('DELETE FROM app_storage WHERE storage_key=?')->execute([$key]);return; }
    $q=$pdo->prepare('INSERT INTO app_storage(storage_key,storage_value) VALUES(?,?) ON CONFLICT (storage_key) DO UPDATE SET storage_value=EXCLUDED.storage_value, updated_at=NOW()');
    $q->execute([$key,$value]);
}
$action=$_GET['action']??'';
try{
switch($action){
case 'bootstrap': $data=[]; foreach(['bitcoinBetUsers','bitcoinBetCurrentUser','bitcoinBetBalance','bitcoinBetWalletRequests','bitcoinBetAviatorWinnersV1','bitcoinBetVirtualMatchesV2','bitcoinBetAdminSession'] as $k){$v=storageGet($pdo,$k);if($v!==null)$data[$k]=$v;} out(['ok'=>true,'data'=>$data]);
case 'session': $id=uid();out(['ok'=>true,'user'=>$id?getUser($pdo,$id):null,'admin'=>admin(),'adminUsername'=>$_SESSION['admin_username']??null]);
case 'register': $d=body();$u=makeUser($pdo,$d);$_SESSION['user_id']=$u['id'];out(['ok'=>true,'user'=>$u]);
case 'login': $d=body();$username=strtolower(trim((string)($d['username']??'')));$pass=(string)($d['password']??''); $q=$pdo->prepare('SELECT * FROM users WHERE username=? AND is_active=true LIMIT 1');$q->execute([$username]);$u=$q->fetch(); if(!$u||!password_verify($pass,$u['password_hash']))out(['ok'=>false,'error'=>'Nom d’utilisateur ou mot de passe incorrect.'],401); $_SESSION['user_id']=$u['id'];out(['ok'=>true,'user'=>safeUser($u)]);
case 'logout': unset($_SESSION['user_id']);out(['ok'=>true]);
case 'admin_login': $d=body(); $username=strtolower(trim((string)($d['username']??'admin'))); $p=(string)($d['password']??''); $a=getAdmin($pdo,$username); if(!$a || !(bool)$a['is_active']) out(['ok'=>false,'error'=>'Compte admin désactivé.'],403); $valid=false; if(strpos($a['password_hash'],'$2')===0){ $valid=password_verify($p,$a['password_hash']); } else { $q=$pdo->prepare("SELECT crypt(?, ?) = ?"); $q->execute([$p,$a['password_hash'],$a['password_hash']]); $valid=(bool)$q->fetchColumn(); } if(!$valid) out(['ok'=>false,'error'=>'Identifiant ou mot de passe administrateur incorrect.'],401); session_regenerate_id(true); $_SESSION['admin_id']=(int)$a['id']; $_SESSION['admin_username']=$a['username']; out(['ok'=>true,'admin'=>['id'=>(int)$a['id'],'username'=>$a['username']]]);
case 'admin_logout': unset($_SESSION['admin_id'],$_SESSION['admin_username']);out(['ok'=>true]);
case 'admin_change_password': if(!admin())out(['ok'=>false,'error'=>'Accès administrateur requis.'],403); $d=body(); $current=(string)($d['current_password']??''); $new=(string)($d['new_password']??''); if(strlen($new)<8)out(['ok'=>false,'error'=>'Le nouveau mot de passe doit contenir au moins 8 caractères.'],422); $q=$pdo->prepare('SELECT password_hash FROM admin_users WHERE id=? AND is_active=true LIMIT 1'); $q->execute([(int)$_SESSION['admin_id']]); $hash=$q->fetchColumn(); if(!$hash) out(['ok'=>false,'error'=>'Admin introuvable.'],404); $ok=false; if(strpos($hash,'$2')===0) $ok=password_verify($current,$hash); else { $qc=$pdo->prepare("SELECT crypt(?, ?) = ?"); $qc->execute([$current,$hash,$hash]); $ok=(bool)$qc->fetchColumn(); } if(!$ok) out(['ok'=>false,'error'=>'Mot de passe actuel incorrect.'],401); $q=$pdo->prepare('UPDATE admin_users SET password_hash=crypt(?, gen_salt(\'bf\')), updated_at=NOW() WHERE id=?'); $q->execute([$new,(int)$_SESSION['admin_id']]); out(['ok'=>true,'message'=>'Mot de passe administrateur modifié.']);
case 'storage': if($_SERVER['REQUEST_METHOD']==='GET'){ $k=(string)($_GET['key']??'');out(['ok'=>true,'value'=>storageGet($pdo,$k)]); } $d=body();$k=(string)($d['key']??'');storageSet($pdo,$k,isset($d['value'])?(string)$d['value']:null,!empty($d['remove']));out(['ok'=>true]);
case 'balance': $id=requireUser();$n=max(0,(float)(body()['balance']??0));$q=$pdo->prepare('UPDATE users SET balance=? WHERE id=?');$q->execute([$n,$id]);out(['ok'=>true,'balance'=>$n]);
case 'wallet_create': $id=requireUser();$d=body();$type=$d['type']??'';$amount=(float)($d['amount']??0); if(!in_array($type,['deposit','withdraw'],true)||$amount<100)out(['ok'=>false,'error'=>'Demande invalide.'],422); $q=$pdo->prepare('SELECT COUNT(*) FROM wallet_requests WHERE user_id=? AND status=\'pending\'');$q->execute([$id]);if((int)$q->fetchColumn()>=5)out(['ok'=>false,'error'=>'Maximum de 5 demandes en attente.'],422); $wid='WR-'.strtoupper(base_convert((string)time(),10,36)).'-'.strtoupper(bin2hex(random_bytes(3))); $q=$pdo->prepare('INSERT INTO wallet_requests(id,user_id,type,amount,operator,phone) VALUES(?,?,?,?,?,?)');$q->execute([$wid,$id,$type,$amount,(string)$d['operator'],(string)$d['phone']]);out(['ok'=>true,'id'=>$wid]);
case 'wallet_decide': if(!admin())out(['ok'=>false,'error'=>'Accès administrateur requis.'],403); $d=body();$id=(string)$d['id'];$decision=(string)$d['decision'];if(!in_array($decision,['approved','rejected'],true))out(['ok'=>false,'error'=>'Décision invalide.'],422); $pdo->beginTransaction(); $q=$pdo->prepare('SELECT * FROM wallet_requests WHERE id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch();if(!$r||$r['status']!=='pending'){$pdo->rollBack();out(['ok'=>false,'error'=>'Demande introuvable ou déjà traitée.'],404);} if($decision==='approved'){ $q=$pdo->prepare('SELECT balance FROM users WHERE id=? FOR UPDATE');$q->execute([$r['user_id']]);$bal=(float)$q->fetchColumn(); if($r['type']==='withdraw' && $bal<(float)$r['amount']){$pdo->rollBack();out(['ok'=>false,'error'=>'Solde insuffisant.'],422);} $new=$r['type']==='deposit'?$bal+(float)$r['amount']:$bal-(float)$r['amount']; $q=$pdo->prepare('UPDATE users SET balance=?, updated_at=NOW() WHERE id=?');$q->execute([$new,$r['user_id']]); } $q=$pdo->prepare('UPDATE wallet_requests SET status=?,processed_at=NOW() WHERE id=?');$q->execute([$decision,$id]);$pdo->commit();out(['ok'=>true]);
case 'wallet_delete_user': if(!admin())out(['ok'=>false,'error'=>'Accès administrateur requis.'],403); $id=(string)(body()['id']??'');$q=$pdo->prepare('DELETE FROM users WHERE id=?');$q->execute([$id]);if(uid()===$id)unset($_SESSION['user_id']);out(['ok'=>true]);
case 'user_update': if(!admin())out(['ok'=>false,'error'=>'Accès administrateur requis.'],403); $d=body();$q=$pdo->prepare('UPDATE users SET name=?,username=?,phone=?,balance=?, updated_at=NOW() WHERE id=?');$q->execute([(string)$d['name'],(string)$d['username'],(string)$d['phone'],(float)$d['balance'],(string)$d['id']]);out(['ok'=>true]);
case 'aviator_winner': $d=body();$q=$pdo->prepare('INSERT INTO aviator_winners(user_id,player_name,gain,multiplier,round_number) VALUES(?,?,?,?,?)');$q->execute([uid(),(string)$d['name'],(float)$d['gain'],(float)$d['multiplier'],(int)$d['round']]);out(['ok'=>true]);
default: out(['ok'=>false,'error'=>'Action inconnue.'],404);
}
}catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); out(['ok'=>false,'error'=>'Erreur serveur: '.$e->getMessage()],500); }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script>window.__BB_BOOTSTRAP=<?php echo json_encode($boot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script>
<style>
*{box-sizing:border-box}
body{margin:0;background:#050505;color:#fff;font:14px Arial,sans-serif;max-width:900px;margin:auto;padding-bottom:80px}
.top{height:72px;background:#201f1b;display:flex;align-items:center;padding:10px 14px;gap:15px;position:sticky;top:0;z-index:50}
.logo{font-weight:900;font-size:22px;color:#ffc400;white-space:nowrap}
.logo b{color:#fff}
.nav{display:flex;gap:14px;overflow:auto}.nav div{min-width:62px;text-align:center;color:#ddd;font-size:21px;cursor:pointer}.nav span{display:block;font-size:11px;margin-top:3px}.nav.on{color:#ffc400}
.balance{background:#181713;border-bottom:1px solid #39362b;padding:10px 14px;display:flex;justify-content:space-between;align-items:center}
.balance small{color:#aaa}.balance strong{color:#ffc400;font-size:18px}
.balance button{border:0;background:#ffc400;color:#111;border-radius:6px;padding:8px 12px;font-weight:bold}
.banner{height:125px;display:grid;place-items:center;background:linear-gradient(90deg,#050505,#1b1b1b,#050505);text-align:center;font-size:26px;font-weight:900;font-style:italic}
.banner em{color:#eeba18}
.aviator-card{margin:12px;border:1px solid #5b4a00;border-radius:12px;overflow:hidden;background:radial-gradient(circle at center,#241800,#0a0800 70%)}
.aviator-card-content{padding:18px;display:flex;align-items:center;justify-content:space-between;gap:15px}
.search{margin:12px;background:#252522;border-radius:25px;padding:13px 18px}
.search input{width:90%;background:none;border:0;outline:0;color:#fff;font-size:15px}
.tabs{padding:4px 12px 12px;display:flex;gap:8px;overflow:auto}
.tabs button{border:0;background:#292925;color:#fff;padding:12px 15px;border-radius:7px;white-space:nowrap}
.leagueTitle{background:#272721;padding:14px;display:flex;justify-content:space-between}
.match{display:flex;gap:10px;padding:15px;border-bottom:1px solid #292929;cursor:pointer}
.teams{flex:1;display:flex;flex-direction:column;gap:7px}.teams small{color:#ff5757}
.odds{display:flex;gap:7px}.odd{width:68px;height:52px;background:#494d4e;color:#fff;border:0;border-radius:7px;font-weight:bold;cursor:pointer}
.odd:hover{background:#ffc400;color:#111}
.coupon{position:sticky;bottom:0;background:#242421;box-shadow:0 -5px 20px #000;z-index:30}
.couponHead{padding:14px;display:flex;justify-content:space-between}
.badge{background:#555;border-radius:50%;padding:6px 9px}
.empty{text-align:center;color:#999;padding:12px}
.row{padding:10px;border-bottom:1px solid #444;font-size:12px}.gold{color:#ffc400;margin-top:4px}
.stake{padding:14px}.stake input{width:100%;padding:12px;border:0;border-radius:6px}
.validate{margin:10px 14px 15px;width:calc(100% - 28px);border:0;padding:14px;background:#ffc400;color:#111;font-size:16px;font-weight:bold;border-radius:8px;cursor:pointer}
.overlay{display:none;position:fixed;inset:0;background:#000b;z-index:100;align-items:flex-end}.overlay.show{display:flex}
.panel{width:min(900px,100%);max-height:90vh;overflow:auto;background:#111;border-radius:20px 20px 0 0}
.head{position:sticky;top:0;background:#20201d;padding:15px;display:flex;justify-content:space-between;align-items:center;z-index:2}
.close{border:0;border-radius:50%;background:#ffc400;width:36px;height:36px;font-size:22px}
.teams2{text-align:center;padding:18px;background:#080808;font-weight:bold}
.market{padding:14px;border-top:1px solid #292929}.market h3{margin:0 0 9px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.marketBtn{border:0;background:#494d4e;color:#fff;padding:12px;border-radius:7px;display:flex;justify-content:space-between;font-weight:bold;cursor:pointer}
.marketBtn b{color:#ffc400}
.toast{position:fixed;left:50%;bottom:85px;transform:translateX(-50%) translateY(100px);background:#252525;border:1px solid #ffc400;color:#fff;padding:14px 18px;border-radius:9px;z-index:300;transition:.3s}
.toast.show{transform:translateX(-50%) translateY(0)}
.ticket{padding:14px;border-bottom:1px solid #333}.ticket-number{color:#ffc400;font-weight:bold}
.page{display:none}.page.show{display:block}

/* --- AVIATOR COMPLET DE CONGOB --- */
:root{--orange:#ff6a00;--dark:#1c252e;--green:#3bb54a;--red:#e53935;--yellow:#ffc107;--blue:#00bfff;--wave-speed:.42s;--wave2-speed:.32s}
.aviator-area{background:#0e1620;padding:10px;position:relative;height:340px;overflow:hidden}
.history{display:flex;gap:14px;overflow-x:auto;font-size:13px;padding:6px 0;position:relative;z-index:60}.history::-webkit-scrollbar{display:none}.history span{white-space:nowrap;font-weight:800}
.status{text-align:center;color:#aaa;font-size:13px;margin-top:8px;position:relative;z-index:60}
.countdown{text-align:center;font-size:18px;font-weight:bold;color:var(--yellow);margin-top:10px;position:relative;z-index:60}
.multiplier{font-size:68px;font-weight:900;text-align:center;margin-top:35px;position:relative;z-index:60}
.flight-track{position:absolute;left:0;right:0;bottom:0;height:155px;overflow:hidden;z-index:5;background:linear-gradient(to bottom,rgba(15,25,35,0),rgba(8,13,18,.40))}
.track-world{position:absolute;left:0;top:0;width:120000px;height:100%;transform:translate3d(0,0,0);will-change:transform;pointer-events:none}
.runway{position:absolute;left:0;bottom:-75px;width:120000px;height:235px;transform:perspective(500px) rotateX(58deg);transform-origin:center bottom;background:repeating-linear-gradient(90deg,rgba(255,255,255,.055) 0 3px,transparent 3px 90px),repeating-linear-gradient(0deg,#202b35 0 13px,#18232c 13px 27px);border-top:3px solid rgba(255,255,255,.15);box-shadow:0 -15px 45px rgba(0,0,0,.5)}
.runway:after{content:"";position:absolute;left:0;top:0;width:100%;height:100%;background:repeating-linear-gradient(90deg,transparent 0 190px,rgba(255,255,255,.9) 190px 250px,transparent 250px 380px);opacity:.48}
.track-start{position:absolute;left:0;bottom:25px;width:430px;height:190px;transform:perspective(500px) rotateX(58deg);transform-origin:center bottom;background:repeating-linear-gradient(to bottom,#303c46 0 15px,#26313a 15px 30px);border-top:4px solid #fff;box-shadow:0 0 30px rgba(255,255,255,.08);z-index:5}
.track-start:before{content:"START";position:absolute;left:25px;top:40px;color:rgba(255,255,255,.75);font-size:17px;font-weight:900;letter-spacing:5px;transform:rotateX(-58deg)}
.speed-lines{position:absolute;left:0;top:0;width:120000px;height:100%;pointer-events:none;background:repeating-linear-gradient(90deg,transparent 0 100px,rgba(255,255,255,.07) 103px,transparent 108px 250px);opacity:.25;z-index:8}
.plane-container{position:absolute;left:38%;bottom:55px;width:105px;height:75px;z-index:30;pointer-events:none;transform:translateX(-50%);will-change:bottom,transform;transition:bottom.1s linear}
.plane{position:absolute;right:0;bottom:8px;font-size:48px;line-height:1;filter:drop-shadow(0 0 5px rgba(255,255,255,.35)) drop-shadow(0 0 8px rgba(255,120,0,.4));transform:rotate(-7deg);transform-origin:center;z-index:5;transition:transform.1s linear}
.plane-container:after{content:"";position:absolute;right:8px;bottom:25px;width:7px;height:7px;border-radius:50%;background:#ff6a00;box-shadow:0 0 8px #ff6a00,0 0 18px rgba(255,106,0,.7);animation:planeLight.45s infinite alternate}
@keyframes planeLight{from{opacity:.25}to{opacity:1}}
.wave{position:absolute;left:-118px;top:31px;width:125px;height:40px;z-index:2;opacity:.95;overflow:hidden}.wave svg{width:100%;height:100%}.wave-path{fill:none;stroke:#00bfff;stroke-width:3;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:12 6;filter:drop-shadow(0 0 4px rgba(0,191,255,.9));animation:waveMove var(--wave-speed) linear infinite}
@keyframes waveMove{from{stroke-dashoffset:0}to{stroke-dashoffset:-36}}
.wave2{position:absolute;left:-80px;top:48px;width:82px;height:23px;z-index:1;opacity:.55;overflow:hidden}.wave2 svg{width:100%;height:100%}.wave2 path{fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-dasharray:7 5;animation:waveMove2 var(--wave2-speed) linear infinite}
@keyframes waveMove2{from{stroke-dashoffset:0}to{stroke-dashoffset:-24}}
.curve{position:absolute;left:0;bottom:0;width:100%;height:150px;background:linear-gradient(to top,rgba(255,0,0,.48),transparent);clip-path:polygon(0 100%,10% 98%,20% 94%,30% 86%,40% 74%,50% 60%,60% 43%,70% 28%,80% 15%,90% 5%,100% 0,100% 100%);opacity:.35;pointer-events:none;z-index:12}
.plane-container.crashing{transition:left 1.1s cubic-bezier(.05,.7,1,1),bottom 1.1s cubic-bezier(.05,.7,1,1),transform 1.1s cubic-bezier(.05,.7,1,1)}.plane-container.crashing.plane{transform:rotate(-28deg) scale(1.15)}.plane-container.hidden{opacity:0}
.bet-box{background:#222c36;margin:10px;border-radius:12px;padding:10px;display:flex;gap:10px}
.bet-controls{flex:1}.input-bet{display:flex;justify-content:space-between;align-items:center;background:#111821;border-radius:20px;padding:8px 14px}
.bet-value{font-weight:bold;cursor:pointer;flex:1;text-align:center;padding:6px 4px}
.input-bet button{width:28px;height:28px;border:none;border-radius:50%;background:#3a4653;color:#fff;font-size:18px}
.chips{display:flex;gap:6px;margin:8px 0}.chips button{background:#2f3a46;border:none;color:#fff;padding:5px 12px;border-radius:12px;font-size:12px}
.auto{width:100%;background:#3a4653;border:2px solid transparent;color:#fff;border-radius:12px;padding:8px;margin-top:6px;font-size:12px;font-weight:bold}.auto.active{background:var(--green);border-color:#76ff83}
.btn-mise{flex:1;min-height:105px;background:var(--green);border:none;border-radius:12px;color:#fff;font-weight:700;font-size:16px;padding:8px;cursor:pointer}.btn-mise.bet-placed{background:var(--red)}.btn-mise.flying{background:var(--yellow);color:#000}.btn-mise.cashed{background:#777}
.live-gain,.live-mult{display:block;font-size:12px;margin-top:5px;font-weight:800}
.keypad-overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;align-items:flex-end;justify-content:center;z-index:200}.keypad-overlay.show{display:flex}
.keypad{width:100%;max-width:430px;background:#1d2732;border-radius:20px 20px 0 0;padding:18px}
.keypad-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.keypad-close{width:32px;height:32px;border:none;border-radius:50%;background:#3a4653;color:#fff;font-size:18px}
.keypad-display{background:#0e1620;border-radius:12px;padding:14px;text-align:right;font-size:25px;font-weight:bold;margin-bottom:12px}
.keypad-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.key{border:none;background:#303c49;color:#fff;height:55px;border-radius:12px;font-size:20px;font-weight:bold}.key.delete{background:#7b3030}.key.clear{background:#684c21}.key.validate{background:var(--green);grid-column:span 3;font-size:16px}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:#111821;display:flex;justify-content:space-around;padding:8px 0;border-top:1px solid #222;z-index:100}
.bottom-nav div{text-align:center;font-size:11px;color:#8a9aa8;cursor:pointer}.bottom-nav.active{color:var(--orange)}
.message{position:fixed;left:50%;bottom:85px;transform:translateX(-50%);background:#000;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;opacity:0;pointer-events:none;transition:.3s;z-index:300;white-space:nowrap}.message.show{opacity:1}

/* NOTIFICATION BLOQUANTE */
.notification-overlay{position:fixed;inset:0;background:rgba(0,0,0,.86);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;padding:15px;z-index:99999;overflow-y:auto}
.notification-card{width:100%;max-width:430px;background:linear-gradient(145deg,#1f2b37,#111820);border:1px solid rgba(255,255,255,.12);border-radius:25px;box-shadow:0 30px 80px rgba(0,0,0,.75),0 0 40px rgba(255,106,0,.12);padding:20px;position:relative;animation:notificationIn.45s ease}
@keyframes notificationIn{0%{opacity:0;transform:translateY(40px) scale(.92)}100%{opacity:1;transform:translateY(0) scale(1)}}
.notification-header{display:flex;align-items:center;gap:10px;margin-bottom:10px}.notification-icon{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#ff8a00,#ff3d00);display:grid;place-items:center;font-size:22px;box-shadow:0 5px 20px rgba(255,106,0,.4)}.notification-title{font-size:19px;font-weight:900}.notification-subtitle{font-size:11px;color:#9eacb9;margin-top:3px}
.warning-box{background:rgba(255,193,7,.08);border:1px solid rgba(255,193,7,.35);border-radius:14px;padding:13px;margin:10px 0;font-size:13px;line-height:1.5}.warning-box strong{color:#ffc107}
.whatsapp-box{background:rgba(37,211,102,.08);border:1px solid rgba(37,211,102,.35);border-radius:14px;padding:13px;margin:10px 0}
.whatsapp-button{width:100%;border:none;padding:13px;border-radius:12px;background:linear-gradient(135deg,#25d366,#128c7e);color:#fff;font-weight:900;font-size:14px;cursor:pointer;margin-top:9px}
.continue-button{width:100%;border:none;padding:13px;border-radius:12px;background:linear-gradient(135deg,#ff7a00,#e84b00);color:#fff;font-weight:900;font-size:14px;cursor:pointer;margin-top:9px}
.terms-link{display:block;text-align:center;margin-top:13px;color:#9fb0c0;font-size:11px;text-decoration:underline;cursor:pointer}
.terms-panel{display:none;margin-top:13px;background:#0d141b;border-radius:14px;padding:14px;border:1px solid rgba(255,255,255,.08);max-height:200px;overflow-y:auto}.terms-panel.show{display:block}
.read-indicator{display:flex;align-items:center;gap:7px;font-size:10px;color:#8d9aa6;margin-top:10px}.read-dot{width:7px;height:7px;background:#ffc107;border-radius:50%;animation:notificationPulse 1s infinite}
@keyframes notificationPulse{0%{box-shadow:0 0 0 0 rgba(255,193,7,.5)}70%{box-shadow:0 0 0 8px rgba(255,193,7,0)}100%{box-shadow:0 0 0 0 rgba(255,193,7,0)}}

/* Fonds / administration */
.funds-overlay{position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:200;display:none;padding:14px;overflow:auto}
.funds-overlay.show{display:flex;align-items:flex-start;justify-content:center}
.funds-panel{width:min(620px,100%);margin:30px auto;background:#171713;border:1px solid #4d4525;border-radius:14px;box-shadow:0 15px 50px #000;overflow:hidden}
.funds-head{padding:15px;background:#25231c;display:flex;justify-content:space-between;align-items:center;color:#ffc400}
.funds-head button{border:0;background:none;color:#fff;font-size:27px;cursor:pointer}
.funds-balance{padding:16px;background:#10100e;border-bottom:1px solid #302e26;font-size:15px}
.funds-balance strong{color:#ffc400;font-size:20px}
.funds-tabs{display:flex;gap:8px;padding:12px}
.fund-tab{flex:1;padding:11px;border:0;border-radius:7px;background:#2b2a25;color:#fff;font-weight:bold}
.fund-tab.active{background:#ffc400;color:#111}
.fund-box{padding:0 14px}
.fund-box h3{margin:8px 0}
.fund-note{font-size:12px;color:#aaa;line-height:1.4}
.fund-box input,.fund-box select,.admin-panel input{width:100%;padding:13px;margin:6px 0;background:#252522;border:1px solid #444;border-radius:7px;color:#fff;outline:none}
.fund-submit{width:100%;padding:13px;margin-top:7px;border:0;border-radius:7px;background:#ffc400;color:#111;font-weight:900;cursor:pointer}
.wallet-list{padding:0 14px 12px}
.wallet-item{background:#23231f;border:1px solid #39372e;border-radius:9px;padding:11px;margin:7px 0}
.wallet-item .line{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.wallet-item small{color:#aaa}
.status-pending{color:#ffc400}.status-approved{color:#35d07f}.status-rejected{color:#ff6868}
.admin-summary{display:flex;gap:8px;padding:12px;flex-wrap:wrap}
.admin-summary span{background:#24241f;padding:9px;border-radius:7px;font-size:12px}
.admin-panel h3{padding:0 14px;margin:14px 0 8px}
.admin-actions{display:flex;gap:7px;margin-top:9px}
.admin-actions button{flex:1;border:0;padding:9px;border-radius:6px;font-weight:bold;cursor:pointer}
.approve{background:#2dbb6d;color:#07170e}.reject{background:#e04b4b;color:#fff}
.admin-logout{margin:5px 14px 18px;padding:10px 14px;background:#333;color:#fff;border:0;border-radius:7px}
.user-edit{display:grid;grid-template-columns:1fr 120px;gap:7px;margin-top:8px}
.user-edit input{margin:0!important}
@media(max-width:520px){.funds-panel{margin:5px auto}.user-edit{grid-template-columns:1fr}.admin-actions{flex-direction:column}}


.auth-overlay{position:fixed;inset:0;background:rgba(0,0,0,.94);z-index:500;display:flex;align-items:center;justify-content:center;padding:18px}
.auth-overlay.hidden{display:none}
.auth-card{width:min(430px,100%);background:#181813;border:1px solid #5b4a00;border-radius:15px;padding:24px;box-shadow:0 20px 70px #000}
.auth-logo{text-align:center;font-size:28px;color:#ffc400;margin-bottom:22px}
.auth-logo b{color:#fff}
.auth-card h2{text-align:center;margin:8px 0;color:#fff}
.auth-note{text-align:center;color:#999;font-size:13px}
.auth-card input{width:100%;padding:14px;margin:7px 0;background:#252522;border:1px solid #45433a;border-radius:8px;color:#fff;outline:none}
.auth-btn{width:100%;padding:14px;margin-top:10px;background:#ffc400;color:#111;border:0;border-radius:8px;font-weight:900;cursor:pointer}
.auth-switch{text-align:center;color:#999;font-size:13px;margin-top:16px}
.auth-switch button{background:none;border:0;color:#ffc400;font-weight:bold;cursor:pointer}
.auth-message{text-align:center;min-height:20px;margin-top:10px;color:#ff7373;font-size:13px}
.auth-message.ok{color:#42d987}


/* ===== INSCRIPTION / CONNEXION ===== */
.auth-overlay{position:fixed;inset:0;background:rgba(0,0,0,.94);z-index:500;display:none;align-items:center;justify-content:center;padding:18px}
.auth-overlay.show{display:flex}
.auth-card{width:min(430px,100%);background:#181713;border:1px solid #5a4b19;border-radius:15px;padding:22px;box-shadow:0 20px 70px #000}
.auth-logo{text-align:center;color:#ffc400;font-size:27px;font-weight:900;margin-bottom:18px}
.auth-tabs{display:flex;gap:7px;margin-bottom:16px}
.auth-tabs button{flex:1;padding:11px;border:0;border-radius:7px;background:#2a2924;color:#fff;font-weight:bold}
.auth-tabs button.active{background:#ffc400;color:#111}
.auth-form input{width:100%;padding:13px;margin:6px 0;background:#252522;border:1px solid #444;border-radius:7px;color:#fff;outline:none}
.auth-submit{width:100%;padding:13px;margin-top:9px;border:0;border-radius:7px;background:#ffc400;color:#111;font-weight:900}
.auth-msg{text-align:center;min-height:20px;margin:8px 0;color:#ff7777;font-size:13px}
.auth-info{font-size:12px;color:#999;text-align:center;line-height:1.4}
.user-chip{color:#ffc400;font-size:12px;margin-right:7px}


#quickLogout{padding:8px 10px;border:1px solid #555;background:#2b2a25;color:#fff;border-radius:6px;cursor:pointer;font-weight:bold}

/* ===== SESSION BAR FIX ===== */
.session-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.session-actions button{cursor:pointer}
.session-user{color:#ffc400;font-weight:bold;font-size:12px}
.account-card{padding:18px}
.account-row{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #333;color:#ddd}
.account-row b{color:#ffc400}
.account-danger{width:100%;padding:13px;border:0;border-radius:8px;background:#d94b4b;color:#fff;font-weight:900;margin-top:16px;cursor:pointer}


/* ===== FINAL AUTH BUTTON FIX ===== */
#accountButton,#quickLogout{position:relative;z-index:9999;pointer-events:auto!important}
#accountMenu{pointer-events:auto!important}

</style>

<style id="virtual-matches-css">
.virtual-info{
  margin:12px;
  padding:13px 15px;
  background:linear-gradient(135deg,#181813,#252318);
  border:1px solid #51471e;
  border-radius:12px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px
}
.virtual-info b{display:block;color:#ffc400;font-size:16px}
.virtual-info span{display:block;color:#999;font-size:11px;margin-top:3px}
.virtual-live-dot{color:#39e27b;font-weight:900;font-size:11px}
.virtual-live-dot::first-letter{font-size:14px}
.virtual-league-title{
  margin:12px 12px 0;
  padding:13px 14px;
  background:#272721;
  display:flex;
  justify-content:space-between;
  align-items:center;
  border-radius:9px 9px 0 0
}
.virtual-league-title span{font-size:10px;color:#ffc400;border:1px solid #6b5812;border-radius:20px;padding:4px 7px}
.virtual-match{
  background:#101010;
  border-bottom:1px solid #292929;
  padding:14px 12px;
  cursor:pointer;
  transition:.15s
}
.virtual-match:hover{background:#171717}
.vm-top{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:9px}
.vm-status{font-size:10px;font-weight:900;padding:5px 8px;border-radius:20px;background:#282828;color:#aaa}
.vm-status.waiting{color:#ffc400;border:1px solid #685710}
.vm-status.live{color:#38e37a;border:1px solid #276f40;background:#0d2617}
.vm-status.finished{color:#aaa;border:1px solid #444}
.vm-clock{font-size:11px;color:#aaa;font-weight:700}
.vm-teams{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:8px;text-align:center}
.vm-team{font-weight:900;font-size:14px}
.vm-team:first-child{text-align:right}
.vm-team:last-child{text-align:left}
.vm-score{min-width:52px;background:#202020;border-radius:8px;padding:7px 6px;font-size:18px;font-weight:900;color:#fff}
.vm-score small{display:block;font-size:9px;color:#ffc400;margin-top:2px}
.vm-odds{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:10px}
.vm-odd{
  border:0;background:#494d4e;color:#fff;border-radius:7px;padding:10px 4px;
  font-weight:900;cursor:pointer
}
.vm-odd:hover{background:#ffc400;color:#111}
.vm-odd span{display:block;font-size:10px;color:#bbb;margin-bottom:2px}
.vm-odd b{font-size:14px}
.vm-odd.disabled{opacity:.45;cursor:not-allowed}
.vm-progress{height:4px;background:#262626;border-radius:5px;overflow:hidden;margin-top:10px}
.vm-progress i{display:block;height:100%;width:0;background:#ffc400;transition:width .3s linear}
.vm-hint{text-align:center;color:#888;font-size:10px;margin-top:7px}
.vm-event{font-size:10px;color:#ffc400;margin-top:7px;min-height:13px}
.coupon-head-clear{
  border:0;background:#3a2929;color:#ff8585;border-radius:6px;padding:6px 9px;font-weight:800;cursor:pointer
}

/* Écran de match direct */
.virtual-live-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.93);z-index:70000;
  display:none;align-items:center;justify-content:center;padding:12px
}
.virtual-live-overlay.show{display:flex}
.virtual-live-panel{
  width:min(620px,100%);max-height:94vh;overflow:auto;
  background:#0d1115;border:1px solid #3a4650;border-radius:18px;
  box-shadow:0 25px 90px #000
}
.vlive-head{
  padding:13px 15px;background:#181f26;display:flex;justify-content:space-between;
  align-items:center;position:sticky;top:0;z-index:3
}
.vlive-head strong{color:#ffc400}
.vlive-close{
  width:36px;height:36px;border:0;border-radius:50%;background:#ffc400;color:#111;
  font-size:22px;font-weight:900;cursor:pointer
}
.vlive-hero{
  padding:18px 12px;text-align:center;
  background:radial-gradient(circle at center,#24313c,#080a0d 70%)
}
.vlive-badge{
  display:inline-block;color:#ffc400;border:1px solid #6d5910;border-radius:20px;
  padding:5px 9px;font-size:10px;font-weight:900;margin-bottom:12px
}
.vlive-teams{
  display:grid;grid-template-columns:1fr 80px 1fr;align-items:center;gap:8px
}
.vlive-team{font-size:16px;font-weight:900}
.vlive-team:first-child{text-align:right}
.vlive-team:last-child{text-align:left}
.vlive-score{font-size:38px;font-weight:900}
.vlive-minute{color:#35e879;font-size:12px;font-weight:900;margin-top:4px}
.vlive-count{
  margin:13px auto 0;background:#0008;border-radius:9px;padding:10px;
  max-width:360px;color:#ffc400;font-weight:900;font-size:20px
}
.vlive-field{
  margin:12px;border-radius:14px;min-height:220px;position:relative;overflow:hidden;
  background:
    linear-gradient(90deg,transparent 49.7%,rgba(255,255,255,.55) 49.8%,rgba(255,255,255,.55) 50.2%,transparent 50.3%),
    linear-gradient(0deg,rgba(255,255,255,.12) 0 2px,transparent 2px 100%),
    repeating-linear-gradient(90deg,#165b35 0 48px,#19653c 48px 96px);
  border:2px solid rgba(255,255,255,.22)
}
.vlive-field:before{
  content:"";position:absolute;left:25%;top:22%;width:50%;height:56%;
  border:2px solid rgba(255,255,255,.5);border-radius:50%
}
.vlive-field:after{
  content:"";position:absolute;left:50%;top:50%;width:8px;height:8px;
  transform:translate(-50%,-50%);background:#fff;border-radius:50%;box-shadow:0 0 12px #fff
}
.vlive-ball{
  position:absolute;font-size:22px;left:50%;top:50%;z-index:2;
  transform:translate(-50%,-50%);transition:.35s
}
.vlive-event-list{padding:0 12px 10px}
.vlive-event{
  display:flex;gap:9px;padding:9px 10px;border-bottom:1px solid #242b31;
  font-size:12px
}
.vlive-event b{color:#ffc400;min-width:42px}
.vlive-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px}
.vlive-stat{background:#171e24;border-radius:9px;padding:10px}
.vlive-stat label{display:block;color:#8d98a2;font-size:10px}
.vlive-stat strong{display:block;margin-top:4px;font-size:15px}
.vlive-market-title{padding:12px 12px 5px;color:#ffc400;font-weight:900;font-size:13px}
.vlive-markets{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;padding:8px 12px 15px}
.vlive-market{
  border:0;background:#343d44;color:#fff;border-radius:7px;padding:10px 4px;
  cursor:pointer;font-weight:800
}
.vlive-market b{display:block;color:#ffc400;margin-top:3px}
.vlive-ended{padding:14px;text-align:center;color:#aaa}
@media(max-width:520px){
  .vm-team{font-size:12px}
  .vm-odd{padding:8px 2px}
  .vlive-teams{grid-template-columns:1fr 65px 1fr}
  .vlive-team{font-size:13px}
}
</style>


<style id="ui-final-user-fixes">
#page-aviator #aviatorRoundBets{display:none!important;}
#page-aviator #aviatorBetsFeed{display:block!important;position:relative;margin:18px 10px 95px;background:#111923;border:1px solid #263545;border-radius:12px;overflow:hidden;color:#fff;clear:both;}
#page-aviator .aviator-feed-note{padding:7px 13px;color:#9aa7b5;font-size:11px;border-bottom:1px solid #293746;}
#page-aviator #aviatorBetsList{max-height:240px;overflow:auto;}
#page-aviator .aviator-feed-head{display:flex;justify-content:space-between;padding:11px 13px;background:#18222d;border-bottom:1px solid #293746;font-size:12px;}
#page-aviator .aviator-bet-row{display:flex;justify-content:space-between;gap:8px;padding:8px 12px;border-bottom:1px solid #202b36;font-size:12px;}
#page-aviator .aviator-bet-row:last-child{border-bottom:0;}
#page-sport .coupon-head-actions{display:flex;align-items:center;gap:6px;}
#page-sport .coupon-head-close{border:0;background:#8b1e1e;color:#fff;border-radius:7px;width:30px;height:30px;font-size:21px;line-height:28px;cursor:pointer;font-weight:900;}
#page-sport .coupon.is-collapsed{display:none!important;}
#page-sport .virtual-tickets-played{margin:12px 0 85px;background:#111923;border:1px solid #263545;border-radius:12px;overflow:hidden;}
#page-sport .vtp-head{display:flex;align-items:center;justify-content:space-between;padding:11px 13px;background:#18222d;color:#fff;}
#page-sport #vtpRefresh{border:0;border-radius:7px;padding:7px 10px;background:#2a3440;color:#fff;cursor:pointer;}
#page-sport .vtp-ticket{padding:10px 12px;border-bottom:1px solid #202b36;color:#ddd;font-size:12px;}
#page-sport .vtp-ticket:last-child{border-bottom:0;}
#page-sport .vtp-ticket strong{color:#fff;}
#page-sport .vtp-status{font-weight:800;}
#page-sport .vtp-wait{color:#facc15;}
#page-sport .vtp-win{color:#22c55e;}
#page-sport .vtp-loss{color:#ef4444;}
#page-sport .vtp-empty{padding:18px;text-align:center;color:#8b95a1;font-size:12px;}
#couponFloatPanel{position:relative;}
#couponFloatClose{position:absolute;right:7px;top:7px;border:0;background:#8b1e1e;color:#fff;border-radius:6px;width:28px;height:28px;font-size:19px;line-height:24px;font-weight:900;cursor:pointer;}
#couponFloatPanel{padding-top:38px!important;}
</style>


<style id="live-betting-fix-css">
.virtual-match .vm-odd:not(:disabled){cursor:pointer}
.virtual-match[data-vmid] .vm-odd:not(:disabled):active{transform:scale(.97)}
.vlive-market{cursor:pointer}
.vlive-market:active{transform:scale(.97)}
.vlive-market-title{display:flex;justify-content:space-between;align-items:center}
.vlive-market-title::after{content:"LIVE";font-size:9px;background:#b91c1c;color:#fff;border-radius:999px;padding:3px 6px}
</style>
<style id="aviator-winners-css">
.aviator-winners-history{margin:12px 0;padding:12px;background:#101010;border:1px solid #2b2b2b;border-radius:12px}
.aviator-winners-history .aviator-feed-head{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px}
.aviator-winner-row{display:flex;justify-content:space-between;align-items:center;padding:9px 10px;margin:5px 0;border-radius:8px;background:#181818;border:1px solid #292929}
.aviator-winner-row .winner-name{font-weight:700}
.aviator-winner-row .winner-gain{font-weight:800}
.aviator-winner-row small{display:block;color:#999;margin-top:2px}
</style>
</head><body>

<!-- ===== INSCRIPTION / CONNEXION ===== -->
<div class="auth-overlay" id="authOverlay">
  <div class="auth-card">
    <div class="auth-logo">₿ BitcoinBet</div>
    <div class="auth-tabs">
      <button id="loginTab" class="active" onclick="showAuth('login')">CONNEXION</button>
      <button id="registerTab" onclick="showAuth('register')">INSCRIPTION</button>
    </div>

    <div id="loginForm" class="auth-form">
      <input id="loginUser" placeholder="Nom d'utilisateur">
      <input id="loginPass" type="password" placeholder="Mot de passe">
      <div id="loginMsg" class="auth-msg"></div>
      <button class="auth-submit" onclick="loginUser()">SE CONNECTER</button>
    </div>

    <div id="registerForm" class="auth-form" style="display:none">
      <input id="regName" placeholder="Nom complet">
      <input id="regUser" placeholder="Nom d'utilisateur">
      <input id="regPhone" type="tel" placeholder="Téléphone">
      <input id="regPass" type="password" placeholder="Mot de passe">
      <input id="regPass2" type="password" placeholder="Confirmer le mot de passe">
      <div id="registerMsg" class="auth-msg"></div>
      <button class="auth-submit" onclick="registerUser()">CRÉER MON COMPTE</button>
    </div>
    <div class="auth-info">Compte sécurisé : vos données sont enregistrées sur le serveur.</div>
  </div>
</div>


<div class="notification-overlay" id="notificationOverlay">
<div class="notification-card">
<div class="notification-header"><div class="notification-icon">🔔</div><div><div class="notification-title">Notification importante</div><div class="notification-subtitle">Bitcoin Bet - Informations clients</div></div></div>
<div class="warning-box"><strong>⚠️ IMPORTANT</strong><br>Ne fermez jamais cette page pendant une transaction. Attendez toujours la confirmation complète.</div>
<div class="whatsapp-box"><b>💬 Groupe WhatsApp des clients</b><p style="font-size:12px;color:#b8c4ce">Rejoignez le groupe officiel pour les infos.</p><button class="whatsapp-button" onclick="window.open('https://chat.whatsapp.com/Bs9MuqZGAK5LAfpLEDnjR8','_blank')">💬 REJOINDRE LE GROUPE WHATSAPP</button></div>
<div class="terms-link" onclick="document.getElementById('termsPanel').classList.toggle('show')">📜 Voir les termes et conditions</div>
<div class="terms-panel" id="termsPanel"><p><strong>1.</strong> Ne fermez pas la page pendant une transaction.</p><p><strong>2.</strong> Messages audio interdits dans WhatsApp.</p><p><strong>3.</strong> Ne partagez jamais votre PIN Mobile Money.</p></div>
<button class="continue-button" onclick="fermerNotification()">✓ J'AI LU — CONTINUER</button>
<div class="read-indicator"><div class="read-dot"></div>Vous devez lire avant d'accéder au jeu.</div>
</div>
</div>

<header class="top">
<div class="logo">₿ <b>Bitcoin</b>Bet</div>
<nav class="nav">
<div class="on" onclick="showPage('sport')">⚽<span>Football</span></div>
<div onclick="showPage('aviator')">✈️<span>Aviator</span></div>
<div>🏀<span>Basket</span></div>
<div>🎾<span>Tennis</span></div>
</nav>
</header>

<div class="balance">
<div><small>Solde unique</small><br><strong id="balance">10 000 000FCFA</strong></div>
<div style="position:relative;display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end">
<span class="user-chip" id="currentUserLabel"></span><button type="button" id="quickLogout" onclick="logoutUser()">🚪 Sortie</button>
<button id="accountButton" onclick="toggleAccountMenu()">👤 Compte</button>
<div id="accountMenu" style="display:none;position:absolute;right:8px;top:64px;z-index:180;background:#181713;border:1px solid #4d4525;border-radius:10px;padding:8px;min-width:190px;box-shadow:0 12px 35px #000">
  <button onclick="openAccount();closeAccountMenu()" style="display:block;width:100%;padding:11px;margin:3px 0;border:0;border-radius:6px;background:#2b2a25;color:#fff;text-align:left">👤 Mon compte</button>
  <button onclick="logoutUser();closeAccountMenu()" style="display:block;width:100%;padding:11px;margin:3px 0;border:0;border-radius:6px;background:#a83232;color:#fff;text-align:left">🚪 Se déconnecter</button>
</div>

<button onclick="openFunds()">💰 Fonds</button>
<button onclick="openAdmin()">⚙️ Admin</button>
<button onclick="logoutUser()">🚪 Sortir</button>
<button onclick="showHistory()">🎫 Mes tickets</button>
</div>
</div>

<div id="page-sport" class="page show">
<section class="banner">MATCHS VIRTUELS<br><em>45s AVANT LE COUP D’ENVOI</em></section>

<section class="virtual-info">
  <div><b>⚽ FOOTBALL VIRTUEL</b><span>Matchs simulés en direct</span></div>
  <div class="virtual-live-dot">● LIVE</div>
</section>

<div class="search">🔍<input id="search" placeholder="Rechercher une équipe"></div>

<div class="virtual-league-title">
  <b>🏆 Ligue des champions — Matchs virtuels</b>
  <span>SIMULATION</span>
</div>

<main id="virtualMatches"></main>

<div class="coupon">
  <div class="couponHead">
    <span><b class="badge" id="count">0</b> Coupon</span>
    <div class="coupon-head-actions">
      <button type="button" class="coupon-head-clear" onclick="window.clearVirtualCoupon()">Vider</button>
      <button type="button" class="coupon-head-close" aria-label="Fermer le coupon">×</button>
    </div>
  </div>
  <div id="coupon"><p class="empty">Votre coupon est vide.</p></div>
</div>
<section id="virtualTicketsPlayed" class="virtual-tickets-played">
  <div class="vtp-head"><b>🎫 TICKETS JOUÉS — MATCHS VIRTUELS</b></div>
  <div id="vtpList"><div class="vtp-empty">Connectez-vous pour voir vos tickets.</div></div>
</section>
</div>

<div id="page-aviator" class="page">
<div class="aviator-area">
<div class="history" id="history"></div>
<div id="aviatorRoundBets" class="aviator-round-bets"><div class="arb-head"><b>🎫 Tickets Aviator</b><span id="arbRound">Manche 1</span></div><div id="arbList"><div class="arb-empty">Aucune mise pour cette manche.</div></div></div>
<div class="status" id="status">PRÉPARATION DE LA MANCHE</div>
<div class="countdown" id="countdown">PROCHAINE MANCHE : 10</div>
<div class="multiplier" id="multi">1.00x</div>
<div class="flight-track" id="flightTrack"><div class="track-world" id="trackWorld"><div class="track-start" id="trackStart"></div><div class="runway" id="runway"></div><div class="speed-lines" id="speedLines"></div></div><div class="plane-container" id="planeContainer"><div class="wave"><svg viewBox="0 0 120 35" preserveAspectRatio="none"><path class="wave-path" d="M0 18 L12 18 L18 5 L24 31 L30 18 L42 18 L48 4 L54 32 L60 18 L72 18 L78 6 L84 30 L90 18 L102 18 L108 3 L114 33 L120 18"></path></svg></div><div class="wave2"><svg viewBox="0 0 80 22" preserveAspectRatio="none"><path d="M0 11 L10 11 L15 4 L20 18 L25 11 L35 11 L40 3 L45 19 L50 11 L60 11 L65 5 L70 17 L80 11"></path></svg></div><div class="plane" id="plane">🛫</div></div></div><div class="curve"></div>
</div>

<div class="bet-box"><div class="bet-controls"><div class="input-bet"><button onclick="changeBet(0,-10)">-</button><span class="bet-value" id="bet1" onclick="openKeypad(0)">50.00</span><button onclick="changeBet(0,10)">+</button></div><div class="chips"><button onclick="setBet(0,100)">100</button><button onclick="setBet(0,200)">200</button><button onclick="setBet(0,500)">500</button><button onclick="setBet(0,10000)">10K</button></div><button class="auto" id="autoBtn1" onclick="toggleAuto(0)">⚡ JEU AUTOMATIQUE : OFF</button></div><button class="btn-mise" id="btnBet1" onclick="handleBet(0)"><span id="btnText1">MISE</span><br><span id="betLabel1">50.00 XAF</span><span class="live-gain" id="gain1">Gain : 50.00 XAF</span><span class="live-mult" id="multBet1">1.00x</span></button></div>

<div class="bet-box"><div class="bet-controls"><div class="input-bet"><button onclick="changeBet(1,-10)">-</button><span class="bet-value" id="bet2" onclick="openKeypad(1)">50.00</span><button onclick="changeBet(1,10)">+</button></div><div class="chips"><button onclick="setBet(1,100)">100</button><button onclick="setBet(1,200)">200</button><button onclick="setBet(1,500)">500</button><button onclick="setBet(1,10000)">10K</button></div><button class="auto" id="autoBtn2" onclick="toggleAuto(1)">⚡ JEU AUTOMATIQUE : OFF</button></div><button class="btn-mise" id="btnBet2" onclick="handleBet(1)"><span id="btnText2">MISE</span><br><span id="betLabel2">50.00 XAF</span><span class="live-gain" id="gain2">Gain : 50.00 XAF</span><span class="live-mult" id="multBet2">1.00x</span></button></div>
</div>

<div id="aviatorWinnersHistory" class="aviator-winners-history">
  <div class="aviator-feed-head"><b>🏆 HISTORIQUE DES GAGNANTS AVIATOR</b><span>Derniers gains</span></div>
  <div id="aviatorWinnersList"><div class="aviator-empty">Aucun gagnant enregistré.</div></div>
</div>

<div id="aviatorBetsFeed" class="aviator-bets-feed">
  <div class="aviator-feed-head"><b>🎫 TICKETS AVIATOR — MISES DES JOUEURS</b><span id="aviatorRoundLabel">Manche —</span></div>
  <div class="aviator-feed-note">Tickets Aviator séparés des tickets des matchs virtuels. Les autres joueurs affichés ici sont simulés dans ce prototype local.</div>
  <div id="aviatorBetsList"><div class="aviator-empty">Aucune mise enregistrée pour cette manche.</div></div>
</div>

<div class="overlay" id="overlay"><div class="panel"><div class="head"><b>Plusieurs marchés</b><button class="close" id="close">×</button></div><div class="teams2" id="matchName"></div><div id="markets"></div></div></div>
<div class="overlay" id="historyOverlay"><div class="panel"><div class="head"><b>🎫 Mes tickets</b><button class="close" onclick="closeHistory()">×</button></div><div id="ticketHistory"></div></div></div>
<div class="toast" id="toast"></div>
<div class="message" id="message"></div>

<div class="keypad-overlay" id="keypadOverlay" onclick="if(event.target.id==='keypadOverlay') closeKeypad()"><div class="keypad"><div class="keypad-title"><b>Modifier la mise</b><button class="keypad-close" onclick="closeKeypad()">×</button></div><div class="keypad-display" id="keypadDisplay">50</div><div class="keypad-grid"><button class="key" onclick="keyPress('1')">1</button><button class="key" onclick="keyPress('2')">2</button><button class="key" onclick="keyPress('3')">3</button><button class="key" onclick="keyPress('4')">4</button><button class="key" onclick="keyPress('5')">5</button><button class="key" onclick="keyPress('6')">6</button><button class="key" onclick="keyPress('7')">7</button><button class="key" onclick="keyPress('8')">8</button><button class="key" onclick="keyPress('9')">9</button><button class="key clear" onclick="keyClear()">C</button><button class="key" onclick="keyPress('0')">0</button><button class="key delete" onclick="keyDelete()">⌫</button><button class="key validate" onclick="validateKeypad()">✓ VALIDER LA MISE</button></div></div></div>


<!-- ===== FONDS / DEPOT / RETRAIT / ADMIN : PROTOTYPE LOCAL ===== -->
<div class="funds-overlay" id="fundsOverlay">
  <div class="funds-panel">
    <div class="funds-head"><b>💰 GESTION DES FONDS</b><button onclick="closeFunds()">×</button></div>
    <div class="funds-balance">Solde disponible : <strong id="fundsBalance">0 FCFA</strong></div>
    <div class="funds-tabs">
      <button class="fund-tab active" onclick="fundTab('deposit',this)">DÉPÔT</button>
      <button class="fund-tab" onclick="fundTab('withdraw',this)">RETRAIT</button>
    </div>
    <div id="depositBox" class="fund-box">
      <h3>Demander un dépôt</h3>
      <p class="fund-note">La demande reste en attente jusqu'à sa validation par l'administrateur.</p>
      <select id="depositOperator"><option>MTN Mobile Money</option><option>Airtel Money</option></select>
      <input id="depositPhone" type="tel" placeholder="Numéro Mobile Money">
      <input id="depositAmount" type="number" min="100" step="100" placeholder="Montant en FCFA">
      <button class="fund-submit" onclick="submitWalletRequest('deposit')">ENVOYER LA DEMANDE</button>
    </div>
    <div id="withdrawBox" class="fund-box" style="display:none">
      <h3>Demander un retrait</h3>
      <p class="fund-note">Le montant sera débité uniquement après validation de l'administrateur.</p>
      <select id="withdrawOperator"><option>MTN Mobile Money</option><option>Airtel Money</option></select>
      <input id="withdrawPhone" type="tel" placeholder="Numéro Mobile Money">
      <input id="withdrawAmount" type="number" min="100" step="100" placeholder="Montant en FCFA">
      <button class="fund-submit" onclick="submitWalletRequest('withdraw')">ENVOYER LA DEMANDE</button>
    </div>
    <h3 style="margin:16px 0 8px">Mes demandes</h3>
    <div id="myWalletRequests" class="wallet-list"></div>
  </div>
</div>

<div class="funds-overlay" id="adminOverlay">
  <div class="funds-panel admin-panel">
    <div class="funds-head"><b>⚙️ ADMINISTRATION</b><button onclick="closeAdmin()">×</button></div>
    <div id="adminLoginBox">
      <p class="fund-note">Connexion administrateur sécurisée par PHP + MySQL.</p>
      <input id="adminUsernameInput" type="text" value="admin" autocomplete="username" placeholder="Nom d’utilisateur administrateur">
      <input id="adminPasswordInput" type="password" autocomplete="current-password" placeholder="Mot de passe administrateur">
      <button class="fund-submit" onclick="adminLogin()">ACCÉDER À L'ADMIN</button>
    </div>
    <div id="adminBox" style="display:none">
      <div class="admin-summary">
        <span>Utilisateurs : <b id="adminUserCount">0</b></span>
        <span>Demandes en attente : <b id="adminPendingCount">0</b></span>
      </div>
      <h3>Demandes dépôt / retrait</h3>
      <div id="adminRequests" class="wallet-list"></div>
      <h3>Utilisateurs et comptes</h3>
      <div id="adminUsers" class="wallet-list"></div>
      <h3>Mot de passe administrateur</h3>
      <input id="adminCurrentPassword" type="password" autocomplete="current-password" placeholder="Mot de passe actuel">
      <input id="adminNewPassword" type="password" autocomplete="new-password" placeholder="Nouveau mot de passe (8 caractères minimum)">
      <button class="fund-submit" onclick="changeAdminPassword()">MODIFIER LE MOT DE PASSE</button>
      <button class="admin-logout" onclick="adminLogout()">SE DÉCONNECTER</button>
    </div>
  </div>
</div>


<div class="funds-overlay" id="accountOverlay">
  <div class="funds-panel">
    <div class="funds-head"><b>👤 MON COMPTE</b><button type="button" onclick="closeAccount()">×</button></div>
    <div id="accountInfo" class="account-card"></div>
    <button type="button" class="account-danger" onclick="logoutUser()">🚪 SORTIE / DÉCONNEXION</button>
  </div>
</div>

<!-- ===== ÉCRAN MATCH DIRECT VIRTUEL ===== -->
<div class="virtual-live-overlay" id="virtualLiveOverlay" aria-hidden="true">
  <div class="virtual-live-panel">
    <div class="vlive-head">
      <strong>⚽ MATCH DIRECT — VIRTUEL</strong>
      <button class="vlive-close" type="button" onclick="closeVirtualLive()">×</button>
    </div>
    <div class="vlive-hero">
      <div class="vlive-badge" id="vliveBadge">MATCH VIRTUEL</div>
      <div class="vlive-teams">
        <div class="vlive-team" id="vliveHome">Équipe A</div>
        <div>
          <div class="vlive-score" id="vliveScore">0 - 0</div>
          <div class="vlive-minute" id="vliveMinute">45s AVANT</div>
        </div>
        <div class="vlive-team" id="vliveAway">Équipe B</div>
      </div>
      <div class="vlive-count" id="vliveCountdown">COUP D’ENVOI DANS 45s</div>
    </div>

    <div class="vlive-field">
      <div class="vlive-ball" id="vliveBall">⚽</div>
    </div>

    <div class="vlive-stats">
      <div class="vlive-stat"><label>POSSESSION</label><strong id="vlivePoss">50% — 50%</strong></div>
      <div class="vlive-stat"><label>TIRS</label><strong id="vliveShots">0 — 0</strong></div>
      <div class="vlive-stat"><label>ATTAQUES</label><strong id="vliveAttacks">0 — 0</strong></div>
      <div class="vlive-stat"><label>CORNERS</label><strong id="vliveCorners">0 — 0</strong></div>
    </div>

    <div class="vlive-event-list" id="vliveEvents">
      <div class="vlive-event"><b>INFO</b><span>En attente du coup d’envoi virtuel.</span></div>
    </div>

    <div class="vlive-market-title">Marchés du match — MISE EN DIRECT</div>
    <div class="vlive-markets">
      <button class="vlive-market" data-live-market="1">1 <b id="liveOdd1">2.50</b></button>
      <button class="vlive-market" data-live-market="N">Nul <b id="liveOddN">3.75</b></button>
      <button class="vlive-market" data-live-market="2">2 <b id="liveOdd2">2.58</b></button>
    </div>

    <div class="vlive-ended" id="vliveEnded" style="display:none">
      🏁 Match virtuel terminé. Le prochain match sera programmé automatiquement.
    </div>
  </div>
</div>

<div class="bottom-nav"><div class="active" onclick="showPage('sport')">☰<br>Menu</div><div onclick="showPage('aviator')">✈️<br>Aviator</div><div>🃏<br>Casino</div><div>👥<br>Mes paris</div></div>

<script>
/* ===== SAFE STORAGE ===== */
const bbStorage=(()=>{
  const cache=Object.assign({}, window.__BB_BOOTSTRAP || {});
  let queue=Promise.resolve();
  function sync(key,value,remove=false){
    queue=queue.then(()=>fetch(location.pathname+"?action=storage",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      credentials:"same-origin",
      body:JSON.stringify({key:key,value:value,remove:remove})
    }).catch(()=>{}));
  }
  return {
    getItem(k){return Object.prototype.hasOwnProperty.call(cache,k)?String(cache[k]):null},
    setItem(k,v){cache[k]=String(v);sync(k,String(v),false)},
    removeItem(k){delete cache[k];sync(k,null,true)},
    clear(){Object.keys(cache).forEach(k=>{delete cache[k];sync(k,null,true)})}
  };
})();


// ===== AUTHENTIFICATION UNIQUE =====
const USERS_KEY="bitcoinBetUsers";
const CURRENT_USER_KEY="bitcoinBetCurrentUser";
function getUsers(){try{const a=JSON.parse(bbStorage.getItem(USERS_KEY)||"[]");return Array.isArray(a)?a:[]}catch(e){return[]}}
function saveUsers(a){bbStorage.setItem(USERS_KEY,JSON.stringify(a))}
function currentUser(){const id=bbStorage.getItem(CURRENT_USER_KEY);if(!id)return null;return getUsers().find(u=>String(u.id)===String(id))||null}
function escapeHtml(v){return String(v??"").replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"}[m]))}
function updateCurrentUserLabel(){const u=currentUser();const label=document.getElementById("currentUserLabel");const out=document.getElementById("quickLogout");if(label)label.textContent=u?"👤 "+(u.username||u.name||"Compte"):"";if(out)out.style.display=u?"inline-block":"none"}
function syncCurrentUser(){const u=currentUser();if(!u)return;u.balance=Number(balance)||0;const a=getUsers(),i=a.findIndex(x=>String(x.id)===String(u.id));if(i>=0){a[i]=u;saveUsers(a)}updateCurrentUserLabel()}
function demoCurrentUser(){return currentUser()||{id:"anonymous",name:"Invité",username:"invité",phone:"",balance:Number(balance)||0}}
function requireLogin(){if(currentUser())return true;showAuth("login");openAuth();toast("Connectez-vous pour jouer");return false}

// ===== BALANCE UNIQUE =====
const BALANCE_KEY="bitcoinBetBalance";
let balance=Number(bbStorage.getItem(BALANCE_KEY));
if(!balance||isNaN(balance)){balance=10000;bbStorage.setItem(BALANCE_KEY,balance);}
// Synchronisation stricte entre le solde interne et window.balance pour tous les modules.
try{Object.defineProperty(window,"balance",{configurable:true,get:()=>balance,set:v=>{const n=Number(v);balance=Number.isFinite(n)?n:0;}});}catch(e){window.balance=balance;}
function formatMoney(v){return new Intl.NumberFormat("fr-FR").format(Math.floor(v))+" FCFA";}
function updateBalance(){
  document.getElementById("balance").textContent=formatMoney(balance);
  bbStorage.setItem(BALANCE_KEY,balance);
  const u=currentUser();
  if(u){
    u.balance=balance;
    const users=getUsers();
    const i=users.findIndex(x=>x.id===u.id);
    if(i>=0){users[i]=u;saveUsers(users);}
  }
}
updateBalance();
let toastTimer=null,messageTimer=null;
function toast(m){const t=document.getElementById("toast");if(!t)return;clearTimeout(toastTimer);t.textContent=m;t.classList.add("show");toastTimer=setTimeout(()=>{t.classList.remove("show");t.textContent="";},1800);}
function showMessage(t){const m=document.getElementById("message");if(!m)return;clearTimeout(messageTimer);m.innerText=t;m.classList.add("show");messageTimer=setTimeout(()=>{m.classList.remove("show");m.innerText="";},1600);}
function clearTransientMessages(){clearTimeout(toastTimer);clearTimeout(messageTimer);[document.getElementById("toast"),document.getElementById("message")].forEach(e=>{if(e){e.classList.remove("show");e.textContent="";}});}
function showPage(p){document.querySelectorAll(".page").forEach(e=>e.classList.remove("show"));document.getElementById("page-"+p).classList.add("show");}
function fermerNotification(){let o=document.getElementById("notificationOverlay");o.style.opacity="0";o.style.transition=".3s";setTimeout(()=>{o.style.display="none";document.body.style.overflow="auto";},300);}
document.body.style.overflow="hidden";

// ===== PARIS SPORTIFS =====
const marketData=[["1X2",[["1","2.50"],["Nul","3.75"],["2","2.58"]]],["Double chance",[["1 ou Nul","1.48"],["1 ou 2","1.27"]]]];
let picks=[];let couponStakeValue="";const overlay=document.getElementById("overlay"),marketsEl=document.getElementById("markets"),nameEl=document.getElementById("matchName");
function add(h,a,m,label,o,matchId){
  const key=h+"|"+a+"|"+m;
  picks=picks.filter(x=>x.key!==key);
  picks.push({key,h,a,m,label,o,vmId:matchId||null});
  renderCoupon(); toast("Ajouté au coupon");
}
function removePick(key){
  const item=picks.find(x=>x.key===key);
  if(!item)return;
  if(window.confirm("Supprimer ce match du coupon ?")){
    picks=picks.filter(x=>x.key!==key);
    renderCoupon();
    toast("Match supprimé du coupon");
  }
}
function clearCoupon(){
  if(!picks.length)return;
  if(window.confirm("Voulez-vous vraiment vider le coupon ?")){picks=[];renderCoupon();toast("Coupon vidé");}
}
function ticketStorageKey(){const u=currentUser();return u?"bitcoinBetTickets:"+u.id:"bitcoinBetTickets:guest";}
function getMyTickets(){try{const a=JSON.parse(bbStorage.getItem(ticketStorageKey())||"[]");return Array.isArray(a)?a:[];}catch(e){return[];}}
function saveMyTickets(a){bbStorage.setItem(ticketStorageKey(),JSON.stringify(a));}
function renderCoupon(){
  const oldStake=document.getElementById("stake");
  if(oldStake&&oldStake.value!=="")couponStakeValue=oldStake.value;
  window.__bbPicks=picks;
  const countEl=document.getElementById("count"),c=document.getElementById("coupon");
  if(countEl)countEl.textContent=picks.length;
  if(window.renderCouponFloat)window.renderCouponFloat();
  if(!c)return;
  if(!picks.length){c.innerHTML='<p class="empty">Votre coupon est vide.</p>';return;}
  let total=1;c.innerHTML="";
  picks.forEach((x,idx)=>{
    total*=Number(x.o)||1;
    const d=document.createElement("div");d.className="row coupon-pick";
    d.innerHTML='<div><b>'+escapeHtml(x.h)+' — '+escapeHtml(x.a)+'</b><br>'+escapeHtml(x.m)+' : '+escapeHtml(x.label)+'<div class="gold">Cote : '+Number(x.o).toFixed(2)+'</div></div><button type="button" class="coupon-remove" data-remove-pick="'+idx+'" title="Supprimer ce match">✕</button>';
    c.appendChild(d);
  });
  const bottom=document.createElement("div");
  bottom.innerHTML='<div style="padding:14px;display:flex;justify-content:space-between"><b>Cote totale</b><b class="gold">'+total.toFixed(2)+'</b></div><div class="stake"><input id="stake" type="number" min="1" placeholder="Montant en FCFA"></div><div id="gain" class="gold" style="padding:0 14px 10px"></div><div class="coupon-actions"><button type="button" class="coupon-stop" id="interruptCoupon">⏹ INTERROMPRE</button><button type="button" class="validate" id="validateTicket">🎫 VALIDER LE TICKET</button></div>';
  c.appendChild(bottom);
  const stakeEl=document.getElementById("stake"),gainEl=document.getElementById("gain"),val=document.getElementById("validateTicket");
  if(stakeEl){stakeEl.value=couponStakeValue;stakeEl.oninput=e=>{couponStakeValue=e.target.value;let a=parseFloat(e.target.value);gainEl.textContent=a>0?"Gain potentiel : "+formatMoney(a*total):"";if(window.renderCouponFloat)window.renderCouponFloat();};if(stakeEl.value){let a=parseFloat(stakeEl.value);if(gainEl)gainEl.textContent=a>0?"Gain potentiel : "+formatMoney(a*total):"";}}
  if(val)val.onclick=()=>{
    if(!currentUser()){toast("Connectez-vous pour valider le coupon");showAuth("login");openAuth();return;}
    const a=parseFloat(stakeEl?.value||0);
    if(!a||a<=0){toast("Mise invalide");return;}
    if(a>balance){toast("Solde insuffisant : "+formatMoney(balance));return;}
    if(!picks.length){toast("Coupon vide");return;}
    if(!confirm("Confirmer la validation du coupon de "+formatMoney(a)+" ?"))return;
    balance-=a;updateBalance();
    const tickets=getMyTickets();
    tickets.unshift({id:"BB-"+Date.now().toString().slice(-8),userId:currentUser().id,date:new Date().toLocaleString("fr-FR"),stake:a,totalOdds:total,potentialGain:a*total,status:"En attente",selections:picks.map(x=>({h:x.h,a:x.a,m:x.m,label:x.label,o:x.o,vmId:x.vmId||null}))});
    saveMyTickets(tickets);picks=[];couponStakeValue="";renderCoupon();toast("Ticket validé");
  };
  const stop=document.getElementById("interruptCoupon");
  if(stop)stop.onclick=()=>{if(confirm("Interrompre la composition et vider le coupon ?")){picks=[];renderCoupon();toast("Coupon annulé");}};
}
window.removePickByIndex=function(i){if(Number.isInteger(i)&&i>=0&&i<picks.length){picks.splice(i,1);renderCoupon();toast("Match supprimé du coupon");}};
window.__bbPicks=picks;
function showHistory(){
  if(!currentUser()){toast("Connectez-vous pour voir vos tickets");showAuth("login");openAuth();return;}
  const over=document.getElementById("historyOverlay"),cont=document.getElementById("ticketHistory"),tickets=getMyTickets();
  if(!tickets.length)cont.innerHTML='<div style="text-align:center;padding:30px;color:#999">🎫 Aucun ticket personnel</div>';
  else{cont.innerHTML="";tickets.forEach(t=>{const div=document.createElement("div");div.className="ticket";div.innerHTML='<div class="ticket-number">'+escapeHtml(t.id)+'</div><small>'+escapeHtml(t.date)+'</small><p>Mise : '+formatMoney(t.stake)+'</p><p>Cote : '+Number(t.totalOdds).toFixed(2)+'</p><p>Gain : '+formatMoney(t.potentialGain)+'</p><p>Statut : '+escapeHtml(t.status||"En attente")+'</p>';cont.appendChild(div);});}
  over.classList.add("show");
}
function closeHistory(){const o=document.getElementById("historyOverlay");if(o)o.classList.remove("show");}

/* ===== AVIATOR CORE RESTAURÉ ===== */
let phase="countdown",mult=1,crashPoint=1,timerInterval=null,flightInterval=null,crashAnimationTimer=null,flightDistance=0,flightSpeed=0,crashFrameAnimation=null;
const trackWorld=document.getElementById("trackWorld"),trackStart=document.getElementById("trackStart"),speedLines=document.getElementById("speedLines"),planeContainer=document.getElementById("planeContainer"),plane=document.getElementById("plane");
let bets=[{amount:50,active:false,cashed:false,placed:false,auto:false},{amount:50,active:false,cashed:false,placed:false,auto:false}];
// Etat Aviator STRICTEMENT isolé du coupon et des matchs sportifs.
let aviatorRoundNumber=0;
let aviatorRoundRecords=[];
const AVIATOR_WINNERS_KEY="bitcoinBetAviatorWinnersV1";
function getAviatorWinners(){
  try{const a=JSON.parse(bbStorage.getItem(AVIATOR_WINNERS_KEY)||"[]");return Array.isArray(a)?a:[];}catch(e){return[];}
}
function saveAviatorWinners(a){try{bbStorage.setItem(AVIATOR_WINNERS_KEY,JSON.stringify(a.slice(-30)));}catch(e){}}
function recordAviatorWinner(name,gain,multiplier,round){
  const g=Number(gain)||0;
  if(g<=0)return;
  const a=getAviatorWinners();
  a.push({name:String(name||"Joueur"),gain:g,mult:Number(multiplier)||0,round:Number(round)||0,time:new Date().toLocaleTimeString("fr-FR",{hour:"2-digit",minute:"2-digit",second:"2-digit"})});
  saveAviatorWinners(a);
  renderAviatorWinners();
}
function renderAviatorWinners(){
  const box=document.getElementById("aviatorWinnersList");
  if(!box)return;
  const a=getAviatorWinners().slice(-10).reverse();
  if(!a.length){box.innerHTML='<div class="aviator-empty">Aucun gagnant enregistré.</div>';return;}
  box.innerHTML=a.map(w=>'<div class="aviator-winner-row"><span class="winner-name">🏆 '+escapeHtml(w.name)+'<small>Manche '+Number(w.round||0)+' · '+escapeHtml(w.time||"")+'</small></span><span class="winner-gain">+'+formatMoney(w.gain)+' XAF<small>'+Number(w.mult||0).toFixed(2)+'x</small></span></div>').join("");
}
function renderAviatorRoundBets(){
  const list=document.getElementById("aviatorBetsList");
  const label=document.getElementById("aviatorRoundLabel");
  if(label)label.innerText="Manche "+aviatorRoundNumber;
  if(!list)return;
  const rows=aviatorRoundRecords.slice();
  if(!rows.length){list.innerHTML='<div class="aviator-empty">Aucune mise enregistrée pour cette manche.</div>';return;}
  list.innerHTML=rows.map(r=>{
    const result=Number(r.result)||0;
    const status=r.status|| (result>0?'+'+formatMoney(result):result<0?'-'+formatMoney(Math.abs(result)):'En vol');
    return '<div class="aviator-bet-row"><span><b>'+escapeHtml(r.name||"Joueur")+'</b><br><span>'+formatMoney(r.amount)+'</span></span><span>'+status+'</span></div>';
  }).join('');
}

let keypadIndex=null,keypadValue="";
function openKeypad(i){if(bets[i].active||phase==="flying"){showMessage("Attendez la prochaine manche");return;}keypadIndex=i;keypadValue=String(bets[i].amount);document.getElementById("keypadDisplay").innerText=keypadValue;document.getElementById("keypadOverlay").classList.add("show");}
function closeKeypad(){document.getElementById("keypadOverlay").classList.remove("show");keypadIndex=null;}
function keyPress(v){if(keypadIndex===null||keypadValue.length>=8)return;if(keypadValue==="0")keypadValue="";keypadValue+=v;document.getElementById("keypadDisplay").innerText=keypadValue;}
function keyClear(){keypadValue="0";document.getElementById("keypadDisplay").innerText=keypadValue;}
function keyDelete(){keypadValue=keypadValue.length<=1?"0":keypadValue.slice(0,-1);document.getElementById("keypadDisplay").innerText=keypadValue;}
function validateKeypad(){if(keypadIndex===null)return;let n=parseInt(keypadValue,10);if(!Number.isFinite(n)||n<50){showMessage("Mise minimum : 50 FCFA");return;}bets[keypadIndex].amount=n;updateBetDisplay(keypadIndex);closeKeypad();}
const hist=document.getElementById("history");
let vals=[1.54,1.18,2.05,43.99,1.39,1.66,2.17,2.14,6.38,5.20,2.97,8.47];
function getHistoryColor(v){return v>=10?"#a855f7":v>=2?"#22c55e":v>=1.5?"#00bfff":"#ef4444";}
function afficherHistorique(){if(!hist)return;hist.innerHTML="";vals.forEach(v=>{let x=document.createElement("span");x.innerText=v.toFixed(2)+"x";x.style.color=getHistoryColor(v);hist.appendChild(x);});}
function ajouterHistorique(v){vals.unshift(Number(v.toFixed(2)));if(vals.length>15)vals.length=15;afficherHistorique();}
function changeBet(i,d){if(bets[i].active||phase==="flying"){showMessage("Attendez la prochaine manche");return;}bets[i].amount=Math.max(50,Math.floor(bets[i].amount+d));updateBetDisplay(i);}
function setBet(i,v){if(bets[i].active||phase==="flying"){showMessage("Attendez la prochaine manche");return;}bets[i].amount=Math.max(50,Number(v)||50);updateBetDisplay(i);}
function updateBetDisplay(i){let n=i+1,a=bets[i].amount;const b=document.getElementById("bet"+n),l=document.getElementById("betLabel"+n),g=document.getElementById("gain"+n),m=document.getElementById("multBet"+n);if(b)b.innerText=a.toFixed(2);if(l)l.innerText=a.toFixed(2)+" XAF";if(g)g.innerText="Gain : "+a.toFixed(2)+" XAF";if(m)m.innerText="1.00x";}
function toggleAuto(i){bets[i].auto=!bets[i].auto;const b=document.getElementById("autoBtn"+(i+1));if(b){b.classList.toggle("active",bets[i].auto);b.innerText=bets[i].auto?"⚡ JEU AUTOMATIQUE : ON":"⚡ JEU AUTOMATIQUE : OFF";}}
function placeAutomaticBets(){bets.forEach((b,i)=>{if(b.auto&&!b.active&&phase==="flying"&&balance>=b.amount){balance-=b.amount;b.active=true;b.placed=true;b.cashed=false;const bt=document.getElementById("btnBet"+(i+1));if(bt)bt.classList.add("bet-placed");const tx=document.getElementById("btnText"+(i+1));if(tx)tx.innerText="ENCAISSER";if(typeof recordAviatorBet==="function")recordAviatorBet(i);}});updateBalance();}
function updateLiveGains(){bets.forEach((b,i)=>{if(phase==="flying"&&b.active){const g=document.getElementById("gain"+(i+1)),m=document.getElementById("multBet"+(i+1));if(g)g.innerText="Gain : "+(b.amount*mult).toFixed(2)+" XAF";if(m)m.innerText=mult.toFixed(2)+"x";}});}
function handleBet(i){if(phase==="flying")cashout(i);else if(bets[i].active)cancelBet(i);else placeBet(i);}
function placeBet(i){if(!requireLogin())return;if(phase!=="countdown"){showMessage("Les mises sont fermées");return;}if(balance<bets[i].amount){toast("Solde insuffisant : "+formatMoney(balance));return;}balance-=bets[i].amount;updateBalance();bets[i].active=true;bets[i].placed=true;bets[i].cashed=false;const bt=document.getElementById("btnBet"+(i+1)),tx=document.getElementById("btnText"+(i+1));if(bt)bt.classList.add("bet-placed");if(tx)tx.innerText="ANNULER";if(typeof recordAviatorBet==="function")recordAviatorBet(i);toast("Mise de "+formatMoney(bets[i].amount)+" enregistrée");}
function cancelBet(i){if(phase!=="countdown"){showMessage("Cette mise ne peut plus être annulée");return;}if(!bets[i].active)return;balance+=bets[i].amount;updateBalance();bets[i].active=false;bets[i].placed=false;const bt=document.getElementById("btnBet"+(i+1)),tx=document.getElementById("btnText"+(i+1));if(bt)bt.classList.remove("bet-placed");if(tx)tx.innerText="MISE";toast("Mise annulée");}
function resetRunway(){flightDistance=0;flightSpeed=0;if(typeof cancelAnimationFrame!=="undefined")cancelAnimationFrame(crashFrameAnimation);if(trackWorld)trackWorld.style.transform="translate3d(0,0,0)";if(trackStart)trackStart.style.opacity="1";if(speedLines)speedLines.style.transform="translate3d(0,0,0)";}
function resetPlane(){clearTimeout(crashAnimationTimer);if(typeof cancelAnimationFrame!=="undefined")cancelAnimationFrame(crashFrameAnimation);if(planeContainer){planeContainer.classList.remove("crashing","hidden");planeContainer.style.left="38%";planeContainer.style.bottom="55px";planeContainer.style.transform="translateX(-50%)";}if(plane)plane.style.transform="rotate(-7deg)";resetRunway();}
function updateInfiniteRunway(){if(!trackWorld)return;let target=1.2+mult*2.2;flightSpeed+=(target-flightSpeed)*.12;flightDistance+=flightSpeed;trackWorld.style.transform="translate3d("+(-flightDistance)+"px,0,0)";if(trackStart)trackStart.style.opacity=flightDistance<450?Math.max(0,1-flightDistance/350):"0";}
function updatePlanePosition(){if(!planeContainer)return;planeContainer.style.left="38%";let p=Math.min(1,Math.max(0,(mult-1)/15));planeContainer.style.bottom=(55+p*45)+"px";if(plane)plane.style.transform="rotate("+(-7-p*10)+"deg)";document.documentElement.style.setProperty("--wave-speed",Math.max(.07,.42/Math.max(1,mult))+"s");document.documentElement.style.setProperty("--wave2-speed",Math.max(.06,.32/Math.max(1,mult))+"s");}

function startAviatorRoundRecord(){aviatorRoundNumber++;aviatorRoundRecords=[]; [{name:"Joueur_A",amount:100,slot:"other-a"},{name:"Joueur_B",amount:250,slot:"other-b"},{name:"Joueur_C",amount:500,slot:"other-c"}].forEach(x=>aviatorRoundRecords.push({name:x.name,amount:x.amount,mult:1,result:0,slot:x.slot,other:true,status:"MISE"})); renderAviatorRoundBets();}
function recordAviatorBet(i){
  const u=currentUser();
  aviatorRoundRecords.push({name:u?(u.username||u.name):"Joueur "+(i+1),amount:bets[i].amount,mult:1,result:0,slot:i,status:"MISE"});
  renderAviatorRoundBets();
}
function updateAviatorCashoutRecord(i,gain,multiplier){const r=aviatorRoundRecords.find(x=>x.slot===i);if(r){r.mult=multiplier;r.result=gain-r.amount;r.status="ENCAISSÉ";renderAviatorRoundBets();}}
function finalizeAviatorCrash(){aviatorRoundRecords.forEach(r=>{if(r.status!=="ENCAISSÉ"){r.result=-r.amount;r.status="CRASH";}r.mult=mult;});renderAviatorRoundBets();}
function startRound(){phase="flying";clearTimeout(window.__aviatorOtherWinnerTimer1);clearTimeout(window.__aviatorOtherWinnerTimer2);startAviatorRoundRecord();mult=1;resetPlane();crashPoint=Math.max(1.01,Math.random()*15+1);placeAutomaticBets();document.getElementById("status").innerText="EN VOL";document.getElementById("countdown").innerText="EN VOL";document.getElementById("multi").style.color="#fff";bets.forEach((b,i)=>{if(b.active){let bt=document.getElementById("btnBet"+(i+1));bt.classList.remove("bet-placed");bt.classList.add("flying");document.getElementById("btnText"+(i+1)).innerText="ENCAISSER";}});flightInterval=setInterval(()=>{mult+=.02;document.getElementById("multi").innerText=mult.toFixed(2)+"x";updateInfiniteRunway();updatePlanePosition();updateLiveGains();if(mult>=crashPoint)crashRound();},80);scheduleAviatorDemoWinners();}

function scheduleAviatorDemoWinners(){
  const round=aviatorRoundNumber;
  const names=["Joueur_A","Joueur_B","Joueur_C"];
  const amounts=[100,250,500];
  [1,2].forEach((n,idx)=>{
    const delay=(2+Math.random()*5+idx*1.2)*1000;
    window["__aviatorOtherWinnerTimer"+n]=setTimeout(()=>{
      if(phase!=="flying"||round!==aviatorRoundNumber)return;
      const multWin=Number((1.6+Math.random()*Math.min(6,Math.max(1,mult-1))).toFixed(2));
      const gain=amounts[idx]*multWin;
      const r=aviatorRoundRecords.find(x=>x.name===names[idx]);
      if(r&&r.status==="MISE"){
        r.status="ENCAISSÉ";r.mult=multWin;r.result=gain-r.amount;
        renderAviatorRoundBets();
        recordAviatorWinner(names[idx],gain,multWin,round);
      }
    },delay);
  });
}

function animateCrashPlane(){planeContainer.classList.add("crashing");planeContainer.style.left="125%";planeContainer.style.bottom="175px";let speed=Math.max(15,flightSpeed*2);let start=performance.now();function frame(now){speed+=.85;flightDistance+=speed;trackWorld.style.transform="translate3d("+(-flightDistance)+"px,0,0)";speedLines.style.transform="translate3d("+(-flightDistance*1.5)+"px,0,0)";document.documentElement.style.setProperty("--wave-speed",".055s");document.documentElement.style.setProperty("--wave2-speed",".045s");let p=Math.min(1,(now-start)/1100);plane.style.transform="rotate("+(-28-p*30)+"deg) scale("+(1.12+p*.1)+")";if(p<1)crashFrameAnimation=requestAnimationFrame(frame);}crashFrameAnimation=requestAnimationFrame(frame);crashAnimationTimer=setTimeout(()=>{planeContainer.classList.add("hidden");},1100);}

function crashRound(){if(phase!=="flying")return;clearInterval(flightInterval);let final=Number(mult.toFixed(2));phase="crash";document.getElementById("status").innerText="CRASH À "+final.toFixed(2)+"x";document.getElementById("multi").innerText=final.toFixed(2)+"x";document.getElementById("multi").style.color="red";animateCrashPlane();bets.forEach((b,i)=>{if(b.active){b.active=false;b.placed=false;let bt=document.getElementById("btnBet"+(i+1));bt.classList.remove("flying","bet-placed");bt.classList.add("cashed");document.getElementById("btnText"+(i+1)).innerText="CRASH";document.getElementById("gain"+(i+1)).innerText="Gain : 0.00 XAF";setTimeout(()=>{bt.classList.remove("cashed");document.getElementById("btnText"+(i+1)).innerText="MISE";updateBetDisplay(i);},2500);}});ajouterHistorique(final);finalizeAviatorCrash();setTimeout(prepareNextRound,3000);}

function cashout(i){if(!currentUser())return;if(phase!=="flying"||!bets[i].active)return;let gain=bets[i].amount*mult;balance+=gain;updateBalance();bets[i].active=false;bets[i].cashed=true;bets[i].placed=false;let bt=document.getElementById("btnBet"+(i+1));bt.classList.remove("flying");bt.classList.add("cashed");document.getElementById("btnText"+(i+1)).innerText="ENCAISSÉ";document.getElementById("gain"+(i+1)).innerText="GAIN : "+gain.toFixed(2)+" XAF";document.getElementById("multBet"+(i+1)).innerText=mult.toFixed(2)+"x";updateAviatorCashoutRecord(i,gain,mult);recordAviatorWinner((currentUser()?.username||currentUser()?.name||"Moi"),gain,mult,aviatorRoundNumber);toast("Gain : "+formatMoney(gain)+" à "+mult.toFixed(2)+"x");setTimeout(()=>{bt.classList.remove("cashed");document.getElementById("btnText"+(i+1)).innerText="MISE";updateBetDisplay(i);},2000);}

function prepareNextRound(){phase="countdown";mult=1;resetPlane();document.getElementById("multi").innerText="1.00x";document.getElementById("multi").style.color="#fff";document.getElementById("status").innerText="FAITES VOS MISES";bets.forEach((b,i)=>{if(!b.active){let bt=document.getElementById("btnBet"+(i+1));bt.classList.remove("flying","bet-placed","cashed");document.getElementById("btnText"+(i+1)).innerText="MISE";updateBetDisplay(i);}});startCountdown();}
function startCountdown(){let s=10;document.getElementById("countdown").innerText="PROCHAINE MANCHE : "+s;clearInterval(timerInterval);timerInterval=setInterval(()=>{s--;if(s>0)document.getElementById("countdown").innerText="PROCHAINE MANCHE : "+s;else{clearInterval(timerInterval);document.getElementById("countdown").innerText="DÉPART!";startRound();}},1000);}

afficherHistorique();renderAviatorWinners();updateBetDisplay(0);updateBetDisplay(1);resetPlane();prepareNextRound();
document.getElementById("search").oninput=e=>{let q=e.target.value.toLowerCase();document.querySelectorAll(".match").forEach(m=>{let text=(m.dataset.h+" "+m.dataset.a).toLowerCase();m.style.display=text.includes(q)?"flex":"none";});};

// ===== FONDS / DEMANDES / ADMIN LOCAL =====
// Les données persistantes sont enregistrées côté serveur via PHP + MySQL.
// Pour de vrais paiements et une administration sécurisée, il faut un serveur.

const WALLET_KEY="bitcoinBetWalletRequests";
const ADMIN_SESSION_KEY="bitcoinBetAdminSession";
const ADMIN_PASSWORD_LOCAL="";

function walletRequests(){
  try{return JSON.parse(bbStorage.getItem(WALLET_KEY)||"[]");}catch(e){return[];}
}
function saveWalletRequests(a){bbStorage.setItem(WALLET_KEY,JSON.stringify(a));}
function demoCurrentUser(){
  let users=[];
  try{users=JSON.parse(bbStorage.getItem(USERS_KEY)||"[]");}catch(e){}
  if(!Array.isArray(users))users=[];
  const currentId=bbStorage.getItem(CURRENT_USER_KEY);
  let u=users.find(x=>x.id===currentId)||users.find(x=>x.loggedIn)||users[0];
  if(!u){
    u={id:"user-local",name:"Joueur",username:"joueur",phone:"",balance:balance};
    users.push(u);bbStorage.setItem(USERS_KEY,JSON.stringify(users));
  }
  return u;
}
function syncCurrentUser(){
  const u=demoCurrentUser();
  u.balance=balance;
  let users=[];
  try{users=JSON.parse(bbStorage.getItem(USERS_KEY)||"[]");}catch(e){}
  if(Array.isArray(users)){
    const i=users.findIndex(x=>x.id===u.id);
    if(i>=0){users[i]=u;bbStorage.setItem(USERS_KEY,JSON.stringify(users));}
  }
}
function openFunds(){document.getElementById("fundsOverlay").classList.add("show");renderFunds();renderWalletRequests();}
function closeFunds(){document.getElementById("fundsOverlay").classList.remove("show");}
function fundTab(type,btn){
  document.querySelectorAll(".fund-tab").forEach(x=>x.classList.remove("active"));
  btn.classList.add("active");
  document.getElementById("depositBox").style.display=type==="deposit"?"block":"none";
  document.getElementById("withdrawBox").style.display=type==="withdraw"?"block":"none";
}
function renderFunds(){
  const b=document.getElementById("fundsBalance");
  if(b)b.textContent=formatMoney(balance);
}
function submitWalletRequest(type){
  const op=document.getElementById(type+"Operator").value;
  const phone=document.getElementById(type+"Phone").value.trim();
  const amount=Number(document.getElementById(type+"Amount").value);
  if(!phone){toast("Entrez le numéro Mobile Money");return;}
  if(!Number.isFinite(amount)||amount<100){toast("Montant minimum : 100 FCFA");return;}
  if(type==="withdraw" && amount>balance){toast("Solde insuffisant");return;}
  const list=walletRequests();
  const u=demoCurrentUser();
  const pending=list.filter(x=>x.userId===u.id&&x.status==="pending").length;
  if(pending>=5){toast("Maximum de 5 demandes en attente");return;}
  list.unshift({
    id:"WR-"+Date.now().toString(36).toUpperCase(),
    userId:u.id,user:u.username||u.name||"joueur",
    type,amount,operator:op,phone,status:"pending",
    date:new Date().toLocaleString("fr-FR")
  });
  saveWalletRequests(list);
  document.getElementById(type+"Phone").value="";
  document.getElementById(type+"Amount").value="";
  renderWalletRequests();
  toast("Demande envoyée à l'administrateur");
}
function renderWalletRequests(){
  const box=document.getElementById("myWalletRequests"); if(!box)return;
  const u=demoCurrentUser();
  const list=walletRequests().filter(x=>x.userId===u.id);
  box.innerHTML=list.length?list.map(x=>{
    const st=x.status==="approved"?"status-approved":x.status==="rejected"?"status-rejected":"status-pending";
    const label=x.status==="approved"?"APPROUVÉ":x.status==="rejected"?"REJETÉ":"EN ATTENTE";
    return `<div class="wallet-item"><div class="line"><b>${x.type==="deposit"?"⬇️ DÉPÔT":"⬆️ RETRAIT"}</b><b>${formatMoney(x.amount)}</b></div><small>${x.operator} • ${x.phone} • ${x.date}</small><div class="${st}" style="margin-top:6px;font-weight:bold">${label}</div></div>`;
  }).join(""):'<div style="color:#999;text-align:center;padding:14px">Aucune demande.</div>';
  renderFunds();
}
function openAdmin(){
  document.getElementById("adminOverlay").classList.add("show");
  const logged=bbStorage.getItem(ADMIN_SESSION_KEY)==="1";
  document.getElementById("adminLoginBox").style.display=logged?"none":"block";
  document.getElementById("adminBox").style.display=logged?"block":"none";
  if(logged)renderAdmin();
}
function closeAdmin(){document.getElementById("adminOverlay").classList.remove("show");}
function adminLogin(){
  const p=document.getElementById("adminPasswordInput").value;
  if(p!==ADMIN_PASSWORD_LOCAL){toast("Mot de passe administrateur incorrect");return;}
  bbStorage.setItem(ADMIN_SESSION_KEY,"1");
  document.getElementById("adminPasswordInput").value="";
  document.getElementById("adminLoginBox").style.display="none";
  document.getElementById("adminBox").style.display="block";
  renderAdmin();toast("Accès administrateur");
}
function adminLogout(){
  bbStorage.removeItem(ADMIN_SESSION_KEY);
  document.getElementById("adminBox").style.display="none";
  document.getElementById("adminLoginBox").style.display="block";
}
function renderAdmin(){
  const list=walletRequests();
  const pending=list.filter(x=>x.status==="pending");
  let users=[];try{users=JSON.parse(bbStorage.getItem(USERS_KEY)||"[]");}catch(e){} if(!Array.isArray(users))users=[];
  document.getElementById("adminPendingCount").textContent=pending.length;
  document.getElementById("adminUserCount").textContent=users.length;
  const rb=document.getElementById("adminRequests");
  rb.innerHTML=list.length?list.map(x=>{
    const status=x.status==="pending"?"status-pending":x.status==="approved"?"status-approved":"status-rejected";
    const label=x.status==="pending"?"EN ATTENTE":x.status==="approved"?"APPROUVÉ":"REJETÉ";
    return `<div class="wallet-item"><div class="line"><b>${escapeHtml(x.type==="deposit"?"⬇️ DÉPÔT":"⬆️ RETRAIT")} — ${escapeHtml(x.user||x.username||"Utilisateur")}</b><b>${formatMoney(x.amount)}</b></div><small>${escapeHtml(x.operator||"")} • ${escapeHtml(x.phone||"")} • ${escapeHtml(x.date||"")}</small><div class="${status}" style="margin-top:6px;font-weight:bold">${label}</div>${x.status==="pending"?`<div class="admin-actions"><button class="approve" onclick="decideWallet('${x.id}','approved')">✓ APPROUVER</button><button class="reject" onclick="decideWallet('${x.id}','rejected')">✕ REJETER</button></div>`:""}</div>`;
  }).join(""):'<div style="color:#999;text-align:center;padding:14px">Aucune demande.</div>';
  const ub=document.getElementById("adminUsers");
  ub.innerHTML=users.length?users.map(u=>`<div class="wallet-item admin-user-card">
    <div class="line"><b>${escapeHtml(u.name||"Sans nom")}</b><b>${formatMoney(Number(u.balance)||0)}</b></div>
    <small>ID : ${escapeHtml(u.id||"-")}</small>
    <div class="admin-edit-grid">
      <input id="an-${escapeHtml(u.id)}" value="${escapeHtml(u.name||"")}" placeholder="Nom complet">
      <input id="au-${escapeHtml(u.id)}" value="${escapeHtml(u.username||"")}" placeholder="Nom utilisateur">
      <input id="ap-${escapeHtml(u.id)}" value="${escapeHtml(u.phone||"")}" placeholder="Téléphone">
      <input id="ab-${escapeHtml(u.id)}" type="number" min="0" value="${Number(u.balance)||0}" placeholder="Solde">
    </div>
    <div class="admin-actions"><button class="approve" onclick="adminEditUser('${u.id}')">✎ MODIFIER</button><button class="reject" onclick="adminDeleteUser('${u.id}')">🗑 SUPPRIMER</button></div>
  </div>`).join(""):'<div style="color:#999;text-align:center;padding:14px">Aucun utilisateur.</div>';
}
function adminEditUser(id){
  if(bbStorage.getItem(ADMIN_SESSION_KEY)!=="1")return;
  let users=[];try{users=JSON.parse(bbStorage.getItem(USERS_KEY)||"[]");}catch(e){};
  const u=users.find(x=>String(x.id)===String(id));if(!u)return;
  const name=document.getElementById("an-"+id)?.value.trim();
  const username=document.getElementById("au-"+id)?.value.trim().toLowerCase();
  const phone=document.getElementById("ap-"+id)?.value.trim();
  const bal=Number(document.getElementById("ab-"+id)?.value);
  if(!name||!username||!Number.isFinite(bal)||bal<0){toast("Informations utilisateur invalides");return;}
  if(users.some(x=>String(x.id)!==String(id)&&String(x.username||"").toLowerCase()===username)){toast("Ce nom d'utilisateur existe déjà");return;}
  u.name=name;u.username=username;u.phone=phone;u.balance=bal;u.updatedAt=new Date().toLocaleString("fr-FR");
  bbStorage.setItem(USERS_KEY,JSON.stringify(users));
  if(String(bbStorage.getItem(CURRENT_USER_KEY))===String(id)){balance=bal;updateBalance();updateCurrentUserLabel();}
  renderAdmin();toast("Compte modifié");
}
function adminDeleteUser(id){
  if(bbStorage.getItem(ADMIN_SESSION_KEY)!=="1")return;
  let users=[];try{users=JSON.parse(bbStorage.getItem(USERS_KEY)||"[]");}catch(e){};
  const u=users.find(x=>String(x.id)===String(id));if(!u)return;
  if(!confirm("Supprimer définitivement le compte de @"+(u.username||u.name)+" ?\nCette action supprimera aussi ses tickets locaux."))return;
  users=users.filter(x=>String(x.id)!==String(id));bbStorage.setItem(USERS_KEY,JSON.stringify(users));
  bbStorage.removeItem("bitcoinBetTickets:"+id);bbStorage.removeItem("bitcoinBetTickets_"+id);
  const wallet=walletRequests().filter(x=>String(x.userId)!==String(id));saveWalletRequests(wallet);
  if(String(bbStorage.getItem(CURRENT_USER_KEY))===String(id)){bbStorage.removeItem(CURRENT_USER_KEY);balance=10000;updateBalance();}
  renderAdmin();updateCurrentUserLabel();toast("Compte supprimé");
}
function decideWallet(id,decision){
  if(bbStorage.getItem(ADMIN_SESSION_KEY)!=="1")return;
  const list=walletRequests();
  const r=list.find(x=>x.id===id);
  if(!r||r.status!=="pending")return;
  if(decision==="approved"){
    if(r.type==="deposit"){
      const u=demoCurrentUser();
      if(r.userId===u.id){balance+=r.amount;updateBalance();syncCurrentUser();}
      else{
        let users=[];try{users=JSON.parse(bbStorage.getItem(USERS_KEY)||"[]");}catch(e){}
        const user=users.find(x=>x.id===r.userId);
        if(user){user.balance=(Number(user.balance)||0)+r.amount;bbStorage.setItem(USERS_KEY,JSON.stringify(users));}
      }
    }else{
      let users=[];try{users=JSON.parse(bbStorage.getItem(USERS_KEY)||"[]");}catch(e){}
      const user=users.find(x=>x.id===r.userId);
      const current=(r.userId===demoCurrentUser().id)?balance:(user?Number(user.balance)||0:0);
      if(current<r.amount){toast("Retrait impossible : solde insuffisant");return;}
      if(r.userId===demoCurrentUser().id){balance-=r.amount;updateBalance();syncCurrentUser();}
      else if(user){user.balance=current-r.amount;bbStorage.setItem(USERS_KEY,JSON.stringify(users));}
    }
  }
  r.status=decision;
  r.processedAt=new Date().toLocaleString("fr-FR");
  saveWalletRequests(list);
  renderAdmin();renderWalletRequests();
  toast(decision==="approved"?"Demande approuvée":"Demande rejetée");
}
function adminSetBalance(id){
  if(bbStorage.getItem(ADMIN_SESSION_KEY)!=="1")return;
  const input=document.getElementById("bal-"+id);
  const n=Number(input.value);
  if(!Number.isFinite(n)||n<0){toast("Solde invalide");return;}
  let users=[];try{users=JSON.parse(bbStorage.getItem(USERS_KEY)||"[]");}catch(e){}
  const u=users.find(x=>x.id===id);
  if(!u)return;
  u.balance=n;bbStorage.setItem(USERS_KEY,JSON.stringify(users));
  if(id===demoCurrentUser().id){balance=n;updateBalance();}
  renderAdmin();toast("Solde mis à jour");
}
renderFunds();







</script>
<script id="final-navigation-and-coupon-fix">
(function(){
  "use strict";
  const $=id=>document.getElementById(id);
  const storage=window.bbStorage || window.localStorage;
  const USERS_KEY="bitcoinBetUsers";
  const CURRENT_KEY="bitcoinBetCurrentUser";
  const ADMIN_KEY="bitcoinBetAdminSession";
  const ADMIN_PASSWORD="";

  function users(){
    try{const a=JSON.parse(storage.getItem(USERS_KEY)||"[]");return Array.isArray(a)?a:[];}catch(e){return[];}
  }
  function saveUsers(a){try{storage.setItem(USERS_KEY,JSON.stringify(a));}catch(e){}}
  function current(){const id=storage.getItem(CURRENT_KEY);return id?users().find(u=>String(u.id)===String(id))||null:null;}
  function home(){
    const pages=document.querySelectorAll('.page');
    pages.forEach(x=>x.classList.remove('show'));
    const sport=$('page-sport');
    if(sport)sport.classList.add('show');
    const note=$('notificationOverlay');
    if(note){note.style.opacity='0';note.style.display='none';}
    document.body.style.overflow='auto';
    const auth=$('authOverlay');
    if(auth){auth.classList.remove('show');auth.classList.add('hidden');auth.style.display='none';}
  }
  function msg(id,text,ok){const e=$(id);if(e){e.textContent=text;e.className='auth-msg'+(ok?' ok':'');}}
  function showAuth(mode){
    const login=mode!=='register';
    if($('loginForm'))$('loginForm').style.display=login?'block':'none';
    if($('registerForm'))$('registerForm').style.display=login?'none':'block';
    if($('loginTab'))$('loginTab').classList.toggle('active',login);
    if($('registerTab'))$('registerTab').classList.toggle('active',!login);
    msg('loginMsg','');msg('registerMsg','');
  }
  function openAuth(){const o=$('authOverlay');if(o){o.classList.remove('hidden');o.classList.add('show');o.style.display='flex';}document.body.style.overflow='hidden';}
  function closeAuth(){const o=$('authOverlay');if(o){o.classList.remove('show');o.classList.add('hidden');o.style.display='none';}document.body.style.overflow='auto';}
  function syncLabel(){
    const u=current();
    const label=$('currentUserLabel'); if(label)label.textContent=u?'👤 '+(u.username||u.name||'Compte'):'';
    const btn=$('accountButton'); if(btn)btn.textContent=u?'👤 '+(u.username||u.name||'Compte'):'👤 Compte';
    const out=$('quickLogout'); if(out)out.style.display=u?'inline-block':'none';
  }
  function syncBalanceToUser(){
    const u=current();if(!u)return;
    u.balance=Number(window.balance)||0;
    const a=users(),i=a.findIndex(x=>String(x.id)===String(u.id));
    if(i>=0){a[i]=u;saveUsers(a);}
  }
  function register(){
    const name=($('regName')?.value||'').trim();
    const username=($('regUser')?.value||'').trim().toLowerCase();
    const phone=($('regPhone')?.value||'').trim();
    const pass=$('regPass')?.value||'';
    const pass2=$('regPass2')?.value||'';
    if(name.length<2)return msg('registerMsg','Entrez votre nom complet.');
    if(username.length<3)return msg('registerMsg','Nom d’utilisateur : 3 caractères minimum.');
    if(!/^[a-z0-9_.-]+$/i.test(username))return msg('registerMsg','Nom d’utilisateur invalide.');
    if(!phone)return msg('registerMsg','Entrez votre numéro de téléphone.');
    if(pass.length<4)return msg('registerMsg','Mot de passe : 4 caractères minimum.');
    if(pass!==pass2)return msg('registerMsg','Les deux mots de passe sont différents.');
    const a=users();
    if(a.some(u=>String(u.username||'').toLowerCase()===username))return msg('registerMsg','Ce nom d’utilisateur existe déjà.');
    const u={id:'USR-'+Date.now().toString(36).toUpperCase()+'-'+Math.random().toString(36).slice(2,7).toUpperCase(),name,username,phone,password:pass,balance:10000,createdAt:new Date().toLocaleString('fr-FR'),loggedIn:true};
    a.push(u);saveUsers(a);storage.setItem(CURRENT_KEY,u.id);storage.setItem('bitcoinBetBalance','10000');
    window.balance=10000;
    if(typeof window.updateBalance==='function')window.updateBalance();
    syncLabel();msg('registerMsg','Compte créé avec succès. Bienvenue !',true);
    setTimeout(home,150);
  }
  function login(){
    const username=($('loginUser')?.value||'').trim().toLowerCase();
    const pass=$('loginPass')?.value||'';
    const a=users();
    const u=a.find(x=>String(x.username||'').toLowerCase()===username&&x.password===pass);
    if(!u)return msg('loginMsg','Nom d’utilisateur ou mot de passe incorrect.');
    u.loggedIn=true;saveUsers(a);storage.setItem(CURRENT_KEY,u.id);
    window.balance=Number(u.balance);if(!Number.isFinite(window.balance))window.balance=10000;
    storage.setItem('bitcoinBetBalance',String(window.balance));
    if(typeof window.updateBalance==='function')window.updateBalance();
    syncLabel();
    home();
    if(typeof window.toast==='function')window.toast('Connexion réussie');
  }
  function logout(){
    const u=current();
    if(u){u.balance=Number(window.balance)||0;u.loggedIn=false;const a=users(),i=a.findIndex(x=>String(x.id)===String(u.id));if(i>=0){a[i]=u;saveUsers(a);}}
    storage.removeItem(CURRENT_KEY);storage.removeItem('bitcoinBetBalance');
    syncLabel();showAuth('login');openAuth();
  }
  function openAccount(){
    const u=current();if(!u){showAuth('login');openAuth();return;}
    const box=$('accountInfo');
    if(box)box.innerHTML='<div class="account-row"><span>Nom</span><b>'+String(u.name||'—')+'</b></div><div class="account-row"><span>Utilisateur</span><b>@'+String(u.username||'—')+'</b></div><div class="account-row"><span>Téléphone</span><b>'+String(u.phone||'—')+'</b></div><div class="account-row"><span>Solde</span><b>'+((Number(window.balance)||0).toLocaleString('fr-FR'))+' FCFA</b></div>';
    if($('accountOverlay'))$('accountOverlay').classList.add('show');
  }
  function toggleAccountMenu(){const m=$('accountMenu');if(m)m.style.display=m.style.display==='block'?'none':'block';}
  function closeAccount(){if($('accountOverlay'))$('accountOverlay').classList.remove('show');}

  function adminLogin(){
    const p=$('adminPasswordInput')?.value||'';
    if(p!==ADMIN_PASSWORD){if(typeof window.toast==='function')window.toast('Mot de passe administrateur incorrect');return;}
    storage.setItem(ADMIN_KEY,'1');
    if($('adminPasswordInput'))$('adminPasswordInput').value='';
    if($('adminLoginBox'))$('adminLoginBox').style.display='none';
    if($('adminBox'))$('adminBox').style.display='block';
    const ad=$('adminOverlay');if(ad){ad.classList.add('show');ad.style.display='flex';ad.style.zIndex='10000';}
    const auth=$('authOverlay');if(auth){auth.classList.remove('show');auth.classList.add('hidden');auth.style.display='none';}
    if(typeof window.renderAdmin==='function')window.renderAdmin();
    if(typeof window.toast==='function')window.toast('Administration ouverte');
  }
  function openAdmin(){
    const ad=$('adminOverlay');if(!ad)return;
    const logged=storage.getItem(ADMIN_KEY)==='1';
    if($('adminLoginBox'))$('adminLoginBox').style.display=logged?'none':'block';
    if($('adminBox'))$('adminBox').style.display=logged?'block':'none';
    ad.classList.add('show');ad.style.display='flex';ad.style.zIndex='10000';
    if(logged&&typeof window.renderAdmin==='function')window.renderAdmin();
  }
  function adminLogout(){storage.removeItem(ADMIN_KEY);if($('adminBox'))$('adminBox').style.display='none';if($('adminLoginBox'))$('adminLoginBox').style.display='block';}

  document.addEventListener('click',function(e){
    const rm=e.target.closest('[data-remove-pick]');
    if(rm){e.preventDefault();e.stopPropagation();if(typeof window.removePick==='function')window.removePick(rm.dataset.removePick);return;}
  },true);

  // Coupon : délégation unique, donc les cotes fonctionnent même après rerendu.
  document.addEventListener('click',function(e){
    const odd=e.target.closest('.odd');
    if(odd){
      e.preventDefault();e.stopPropagation();
      const match=odd.closest('.match');
      if(!match)return;
      const btns=[...match.querySelectorAll('.odd')];
      const idx=btns.indexOf(odd);
      const labels=['1','Nul','2'];
      if(typeof window.add==='function')window.add(match.dataset.h||'',match.dataset.a||'','1X2',labels[idx]||'Pari',Number(odd.dataset.o));
      return;
    }
    const marketBtn=e.target.closest('.marketBtn');
    if(marketBtn && typeof window.add==='function'){
      const matchName=($('matchName')?.textContent||'').split(' — ');const title=marketBtn.parentElement?.parentElement?.querySelector('h3')?.textContent||'Marché';
      const sp=marketBtn.querySelector('span'),bp=marketBtn.querySelector('b');
      if(sp&&bp)window.add(matchName[0]||'',matchName[1]||'',title,sp.textContent.trim(),Number(bp.textContent));
    }
  },true);

  window.showAuth=showAuth;window.showRegister=()=>showAuth('register');window.showLogin=()=>showAuth('login');window.openAuth=openAuth;window.closeAuth=closeAuth;
  window.registerUser=register;window.loginUser=login;window.logoutUser=logout;window.openAccount=openAccount;window.closeAccount=closeAccount;window.toggleAccountMenu=toggleAccountMenu;
  window.openAdmin=openAdmin;window.adminLogin=adminLogin;window.adminLogout=adminLogout;window.removePick=removePick;window.clearCoupon=clearCoupon;window.showHistory=showHistory;window.closeHistory=closeHistory;

  function bind(){
    if($('loginTab'))$('loginTab').onclick=()=>showAuth('login');
    if($('registerTab'))$('registerTab').onclick=()=>showAuth('register');
    if($('accountButton'))$('accountButton').onclick=toggleAccountMenu;
    if($('quickLogout'))$('quickLogout').onclick=logout;
    const adminClose=$('adminOverlay')?.querySelector('.funds-head button');
    if(adminClose)adminClose.onclick=(ev)=>{ev.preventDefault();ev.stopPropagation();closeAdmin();};
    const adminOv=$('adminOverlay');
    if(adminOv)adminOv.onclick=(ev)=>{if(ev.target===adminOv)closeAdmin();};
    syncLabel();
    const u=current();
    if(u){window.balance=Number(u.balance)||10000;if(typeof window.updateBalance==='function')window.updateBalance();home();}
    else{showAuth('login');openAuth();}
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bind,{once:true});else bind();
})();
</script>
<style>

.aviator-bets-feed{margin:10px;background:#171f27;border:1px solid #303b46;border-radius:12px;overflow:hidden}.aviator-feed-head{padding:12px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #303b46;color:#ffc107}.aviator-feed-head span{font-size:11px;color:#9eacb9}.aviator-bet-line{display:flex;justify-content:space-between;gap:8px;padding:9px 12px;border-bottom:1px solid #29323a;font-size:12px}.aviator-bet-line:last-child{border-bottom:0}.aviator-bet-line .gain{color:#53d769;font-weight:bold}.aviator-bet-line .lost{color:#ff6262;font-weight:bold}.aviator-empty{padding:14px;text-align:center;color:#89939c;font-size:12px}
.coupon-tools{display:flex;gap:8px;padding:10px 14px 0}.coupon-tools button{flex:1;border:0;border-radius:7px;padding:10px;font-weight:bold;cursor:pointer}.coupon-clear{background:#8e2525;color:#fff}.coupon-stop{background:#3a3a35;color:#fff}.coupon-item{position:relative;padding-right:42px}.coupon-remove{position:absolute;right:10px;top:10px;width:30px;height:30px;border:0;border-radius:50%;background:#8e2525;color:#fff;font-weight:bold;cursor:pointer}
.toast{pointer-events:none}.message{pointer-events:none}

/* Final navigation/admin/coupon fixes */
#authOverlay{z-index:50000!important}
#adminOverlay{z-index:60000!important}
#adminOverlay.show{display:flex!important}
#coupon{position:relative;z-index:40}
#coupon .validate{cursor:pointer!important;pointer-events:auto!important}
.odd,.marketBtn{cursor:pointer!important;pointer-events:auto!important}
</style>

<style id="user-final-ui-fixes">
.aviator-round-bets{margin:10px 0 90px;background:#151b21;border:1px solid #303944;border-radius:12px;overflow:hidden;position:relative;z-index:60}
.arb-head{display:flex;justify-content:space-between;align-items:center;padding:11px 13px;border-bottom:1px solid #303944;font-size:13px}
.arb-head span{color:#ffc400;font-weight:800}
.arb-row{display:grid;grid-template-columns:1.2fr .8fr .8fr .9fr;gap:6px;padding:9px 12px;border-bottom:1px solid #252c33;font-size:12px}
.arb-row:last-child{border-bottom:0}.arb-name{font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.arb-muted{color:#9aa4af}.arb-win{color:#22c55e;font-weight:800}.arb-loss{color:#ef4444;font-weight:800}.arb-empty{padding:14px;color:#8d98a3;text-align:center;font-size:12px}
.coupon-item{position:relative;padding-right:42px}.coupon-remove{position:absolute;right:10px;top:10px;width:28px;height:28px;border:0;border-radius:50%;background:#3a2020;color:#ff6b6b;font-size:18px;cursor:pointer}.coupon-actions{display:flex;gap:8px;padding:0 14px 12px}.coupon-clear{flex:1;background:#3a2020;color:#ff8a8a;border:1px solid #633333;border-radius:8px;padding:10px;font-weight:800;cursor:pointer}.coupon-continue{flex:1;background:#202a35;color:#fff;border:1px solid #3b4a5b;border-radius:8px;padding:10px;font-weight:800;cursor:pointer}
#toast,#message{pointer-events:none}
#adminOverlay .funds-panel{position:relative}
#adminOverlay .funds-head button{z-index:100;position:relative;cursor:pointer;pointer-events:auto}
</style>

<style id="final-user-fixes-css">
#coupon .coupon-actions{display:flex;gap:8px;padding:10px 14px;flex-wrap:wrap}
#coupon .coupon-actions button{flex:1;min-width:130px;border:0;border-radius:9px;padding:10px;font-weight:800;cursor:pointer}
#coupon .cancel-coupon{background:#333;color:#fff}
#coupon .remove-pick{float:right;background:#a83232;color:#fff;border:0;border-radius:6px;padding:5px 8px;cursor:pointer}
#coupon .row{overflow:hidden}
#aviatorRoundBets{margin:14px 0 90px;background:#0c1218;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px}
#aviatorRoundBets h3{margin:0 0 10px;font-size:15px}
.avi-round{border-top:1px solid rgba(255,255,255,.08);padding:9px 0}
.avi-round:first-child{border-top:0}
.avi-player{display:flex;justify-content:space-between;gap:8px;padding:4px 0;color:#ddd;font-size:13px}
.avi-player b{color:#ffc400}
.admin-close-fixed{cursor:pointer!important;pointer-events:auto!important;position:relative;z-index:10}
</style>

<script id="final-user-behavior-fixes">
(function(){
  "use strict";
  const $=id=>document.getElementById(id);
  const store=window.bbStorage||window.localStorage;
  const USERS="bitcoinBetUsers", CURRENT="bitcoinBetCurrentUser";
  const ticketKey=()=>{
    const id=store.getItem(CURRENT);
    return id ? "bitcoinBetTickets:"+id : "bitcoinBetTickets:guest";
  };
  const getTickets=()=>{try{const a=JSON.parse(store.getItem(ticketKey())||"[]");return Array.isArray(a)?a:[]}catch(e){return[]}};
  const saveTickets=a=>{try{store.setItem(ticketKey(),JSON.stringify(a))}catch(e){}};
  const getCurrent=()=>{try{const id=store.getItem(CURRENT),a=JSON.parse(store.getItem(USERS)||"[]");return id?(a.find(u=>String(u.id)===String(id))||null):null}catch(e){return null}};

  // ===== Messages : toujours temporaires, même si plusieurs messages se suivent =====
  let toastTimer=null,msgTimer=null;
  window.toast=function(text){
    const el=$('toast'); if(!el)return;
    clearTimeout(toastTimer); el.textContent=String(text||''); el.classList.add('show');
    toastTimer=setTimeout(()=>{el.classList.remove('show');el.textContent='';},2300);
  };
  window.showMessage=function(text){
    const el=$('message'); if(!el)return;
    clearTimeout(msgTimer); el.textContent=String(text||''); el.classList.add('show');
    msgTimer=setTimeout(()=>{el.classList.remove('show');el.textContent='';},1800);
  };

  // ===== Coupon : suppression d'une sélection + annulation complète + confirmation =====
  let couponState = window.__couponState || [];
  window.__couponState = couponState;
  window.__bbPicks = couponState;
  function recalcCoupon(){
    let total=1; couponState.forEach(x=>total*=Number(x.o)||1); return total;
  }
  function removePick(index){
    const item=couponState[index]; if(!item)return;
    const ok=window.confirm('Supprimer ce match du coupon ?\n\n'+item.h+' — '+item.a+'\n'+item.m+' : '+item.label);
    if(!ok)return;
    couponState.splice(index,1); window.__bbPicks=couponState; renderCouponFixed(); window.renderCouponFloat&&window.renderCouponFloat(); window.toast('Match supprimé du coupon');
  }
  function cancelCoupon(){
    if(!couponState.length)return;
    if(!window.confirm('Annuler la composition du coupon et supprimer toutes les sélections ?'))return;
    couponState.length=0; window.__bbPicks=couponState; renderCouponFixed(); window.renderCouponFloat&&window.renderCouponFloat(); window.toast('Coupon annulé');
  }
  function renderCouponFixed(){
    window.__bbPicks = couponState;
    const c=$('coupon'),count=$('count'); if(!c)return;
    if(count)count.textContent=couponState.length;
    if(!couponState.length){c.innerHTML='<p class="empty">Votre coupon est vide.</p>';return;}
    const total=recalcCoupon(); c.innerHTML='';
    couponState.forEach((x,i)=>{
      const d=document.createElement('div'); d.className='row';
      d.innerHTML='<button class="remove-pick" type="button" data-remove-pick="'+i+'">× Supprimer</button><b>'+esc(x.h)+' — '+esc(x.a)+'</b><br>'+esc(x.m)+' : '+esc(x.label)+'<div class="gold">Cote : '+Number(x.o).toFixed(2)+'</div>';
      c.appendChild(d);
    });
    const bottom=document.createElement('div');
    bottom.innerHTML='<div style="padding:14px;display:flex;justify-content:space-between"><b>Cote totale</b><b class="gold">'+total.toFixed(2)+'</b></div>'+
      '<div class="stake"><input id="stake" type="number" min="1" placeholder="Montant en FCFA"></div>'+
      '<div id="gain" class="gold" style="padding:0 14px 10px"></div>'+
      '<div class="coupon-actions"><button class="cancel-coupon" id="cancelCoupon" type="button">✕ ANNULER LE COUPON</button><button class="validate" id="validateTicket" type="button">🎫 VALIDER LE TICKET</button></div>';
    c.appendChild(bottom);
    const stake=$('stake'),gain=$('gain');
    stake.oninput=()=>{const a=parseFloat(stake.value);gain.textContent=a>0?'Gain potentiel : '+formatMoney(a*total):'';};
    $('cancelCoupon').onclick=cancelCoupon;
    $('validateTicket').onclick=()=>{
      const u=getCurrent(); if(!u){window.showAuth&&window.showAuth('login');window.openAuth&&window.openAuth();window.toast('Connectez-vous pour valider le ticket');return;}
      const a=parseFloat(stake.value);
      if(!a||a<=0){window.toast('Mise invalide');return;}
      if(a>Number(balance)){window.toast('Solde insuffisant : '+formatMoney(balance));return;}
      if(!window.confirm('Confirmer la validation du coupon pour '+formatMoney(a)+' ?'))return;
      // Débit réel du solde uniquement au moment de la validation du ticket.
      balance=Number(balance)-a;
      updateBalance();
      syncCurrentUser();
      const tickets=getTickets();
      tickets.unshift({id:'BB-'+Date.now().toString().slice(-8),date:new Date().toLocaleString('fr-FR'),userId:u.id,username:u.username||u.name,stake:a,totalOdds:total,potentialGain:a*total,status:'En attente',selections:couponState.map(x=>({h:x.h,a:x.a,m:x.m,label:x.label,o:x.o,vmId:x.vmId||null}))});
      saveTickets(tickets); couponState.length=0; window.__bbPicks=couponState; renderCouponFixed(); window.renderCouponFloat&&window.renderCouponFloat(); window.toast('Ticket validé');
    };
  }
  function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
  window.add=function(h,a,m,label,o,matchId){
    h=String(h||''); a=String(a||''); m=String(m||'1X2'); label=String(label||''); o=Number(o);
    if(!h||!a||!Number.isFinite(o)||o<=0){window.toast&&window.toast('Sélection invalide');return false;}
    const key=h+'|'+a+'|'+m;
    const idx=couponState.findIndex(x=>x.key===key);
    if(idx>=0) couponState.splice(idx,1);
    couponState.push({key,h,a,m,label,o,vmId:matchId||null});
    window.__couponState=couponState;
    window.__bbPicks=couponState;
    renderCouponFixed();
    window.renderCouponFloat&&window.renderCouponFloat();
    window.toast&&window.toast('Ajouté au coupon');
    return true;
  };
  window.renderCoupon=renderCouponFixed;
  // Toutes les cotes utilisent le coupon personnel corrigé.
  document.addEventListener('click',function(e){
    const odd=e.target.closest('.odd');
    if(odd){
      e.preventDefault();e.stopPropagation();
      const m=odd.closest('.match');if(!m)return;
      const bs=[...m.querySelectorAll('.odd')],idx=bs.indexOf(odd),labels=['1','Nul','2'];
      window.add(m.dataset.h||'',m.dataset.a||'','1X2',labels[idx]||'Pari',Number(odd.dataset.o));
      return;
    }
    const mb=e.target.closest('.marketBtn');
    if(mb){
      e.preventDefault();e.stopPropagation();
      const match=(($('matchName')?.textContent)||'').split(' — '),section=mb.closest('.market'),title=section?.querySelector('h3')?.textContent||'Marché';
      const sp=mb.querySelector('span'),bp=mb.querySelector('b');
      if(sp&&bp)window.add(match[0]||'',match[1]||'',title,sp.textContent.trim(),Number(bp.textContent));
      return;
    }
  },true);
  document.addEventListener('click',e=>{
    const rm=e.target.closest('[data-remove-pick]');
    if(rm){e.preventDefault();e.stopPropagation();removePick(Number(rm.dataset.removePick));}
  },true);

  // ===== Tickets : chaque client ne voit que ses propres tickets =====
  window.showHistory=function(){
    const over=$('historyOverlay'),cont=$('ticketHistory'); if(!over||!cont)return;
    const u=getCurrent();
    if(!u){window.showAuth&&window.showAuth('login');window.openAuth&&window.openAuth();window.toast('Connectez-vous pour voir vos tickets');return;}
    const tickets=getTickets();
    if(!tickets.length)cont.innerHTML='<div style="text-align:center;padding:30px;color:#999">🎫 Aucun ticket personnel</div>';
    else{
      cont.innerHTML=''; tickets.forEach(t=>{
        const div=document.createElement('div');div.className='ticket';
        div.innerHTML='<div class="ticket-number">'+esc(t.id)+'</div><small>'+esc(t.date)+'</small><p>Mise : '+formatMoney(t.stake)+'</p><p>Cote : '+Number(t.totalOdds).toFixed(2)+'</p><p>Gain : '+formatMoney(t.potentialGain)+'</p><p>Statut : '+esc(t.status||'En attente')+'</p>';
        cont.appendChild(div);
      });
    }
    over.classList.add('show'); over.style.display='flex';
  };
  window.closeHistory=function(){const x=$('historyOverlay');if(x){x.classList.remove('show');x.style.display='none';}};

  // ===== Administration : fermeture fiable par X, clic extérieur et Échap =====
  window.closeAdmin=function(){const x=$('adminOverlay');if(x){x.classList.remove('show');x.style.display='none';x.setAttribute('aria-hidden','true');}};
  const admin=$('adminOverlay');
  if(admin){
    admin.setAttribute('aria-hidden','true');
    admin.addEventListener('click',e=>{if(e.target===admin)window.closeAdmin();});
    const x=admin.querySelector('.funds-head button'); if(x){x.classList.add('admin-close-fixed');x.type='button';x.onclick=e=>{e.preventDefault();e.stopPropagation();window.closeAdmin();};}
  }
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){window.closeAdmin();window.closeHistory();const o=$('overlay');if(o)o.classList.remove('show');}},true);

  // Si la page est déjà ouverte, forcer le coupon à être rendu avec les nouveaux boutons.
  try{renderCouponFixed();}catch(e){}
})();
</script>

<style>
.admin-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:9px}.admin-edit-grid input{margin:0!important}.admin-user-card{padding:12px}.remove-pick{border:0;background:#8b1e1e;color:#fff;border-radius:8px;padding:7px 9px;cursor:pointer;font-weight:800;float:right}.cancel-coupon{border:0;border-radius:10px;padding:12px;background:#333;color:#fff;font-weight:800;cursor:pointer}.coupon-actions{display:flex;gap:8px;padding:0 14px 14px}.coupon-actions button{flex:1}@media(max-width:520px){.admin-edit-grid{grid-template-columns:1fr}}
/* Corrections finales : messages, coupon, tickets privés, Aviator et admin */
.toast,.message{pointer-events:none;transition:opacity .2s ease,transform .2s ease}
.toast:not(.show),.message:not(.show){opacity:0}
.coupon-pick{display:flex!important;align-items:center;justify-content:space-between;gap:10px}
.coupon-remove{border:0;background:#8b1e1e;color:#fff;border-radius:8px;padding:8px 10px;cursor:pointer;font-weight:800;flex:none}
.coupon-actions{display:flex;gap:8px;padding:0 14px 14px}.coupon-actions button{flex:1}
.coupon-stop{border:0;border-radius:10px;padding:12px;background:#333;color:#fff;font-weight:800;cursor:pointer}
.aviator-round-bets{margin:10px 10px 90px;background:#111923;border:1px solid #263545;border-radius:12px;overflow:hidden;color:#fff}
.arb-head{display:flex;justify-content:space-between;padding:11px 13px;background:#18222d;border-bottom:1px solid #293746;font-size:12px}
#arbList{max-height:190px;overflow:auto}.arb-row{display:flex;justify-content:space-between;gap:8px;padding:8px 12px;border-bottom:1px solid #202b36;font-size:12px}.arb-row:last-child{border-bottom:0}.arb-name{font-weight:700}.arb-status{color:#9ca3af}.arb-win{color:#22c55e;font-weight:800}.arb-loss{color:#ef4444;font-weight:800}
#adminOverlay .funds-panel{position:relative}#adminOverlay .funds-head button{cursor:pointer;z-index:5;position:relative}
</style>
<script>
(function(){
  // Fermeture fiable de tous les overlays concernés.
  window.closeAdmin=function(){const o=document.getElementById('adminOverlay');if(o){o.classList.remove('show');o.style.display='none';}document.body.style.overflow='auto';};
  window.closeHistory=function(){const o=document.getElementById('historyOverlay');if(o)o.classList.remove('show');};
  window.closeAccount=function(){const o=document.getElementById('accountOverlay');if(o)o.classList.remove('show');};
  window.closeFunds=function(){const o=document.getElementById('fundsOverlay');if(o)o.classList.remove('show');};
  document.addEventListener('click',function(e){
    const o=e.target.closest('.funds-overlay,.overlay');
    if(o&&e.target===o){
      if(o.id==='adminOverlay')window.closeAdmin();
      else if(o.id==='historyOverlay')window.closeHistory();
      else o.classList.remove('show');
    }
  });
  // Fermer le message de notification initial après quelques secondes.
  setTimeout(function(){const o=document.getElementById('notificationOverlay');if(o&&getComputedStyle(o).display!=='none')fermerNotification();},3200);

  // Tickets strictement personnels par utilisateur.
  window.showHistory=showHistory;

  // ===== AFFICHAGE DES MISES PAR MANCHE AVIATOR =====
  let roundNo=0, roundPlayers=[];
  const names=['Chris','Junior','Kevin','Sarah','Mika','Dylan','Aline'];
  function money(n){return formatMoney(n);}
  function newRoundBoard(){
    roundNo++;roundPlayers=names.slice(0,4+Math.floor(Math.random()*3)).map((name,i)=>({name,bet:[50,100,200,500,1000][Math.floor(Math.random()*5)],status:'EN ATTENTE',gain:0}));
    renderRoundBoard();
  }
  function renderRoundBoard(){
    const box=document.getElementById('aviatorRoundBets'),list=document.getElementById('arbList'),lab=document.getElementById('arbRound');if(!box||!list)return;
    if(lab)lab.textContent='Manche '+roundNo;
    const arr=roundPlayers.slice();const u=currentUser();
    bets.forEach((b,i)=>{if(b.active||b.cashed){const n=u?(u.username||u.name||'Moi'):'Moi';let x=arr.find(v=>v.name===n);if(!x){x={name:n,bet:b.amount,status:'EN VOL',gain:0};arr.unshift(x);}x.bet=b.amount;x.status=b.cashed?'ENCAISSÉ':'EN VOL';x.gain=b.cashed?b.amount*mult:0;}});
    list.innerHTML=arr.map(x=>'<div class="arb-row"><span><span class="arb-name">'+escapeHtml(x.name)+'</span><br><span class="arb-status">'+escapeHtml(x.status)+'</span></span><span>'+money(x.bet)+(x.gain>0?' <b class="arb-win">→ '+money(x.gain)+'</b>':'')+'</span></div>').join('');
  }
  const oldPrepare=window.prepareNextRound;
  // Le jeu utilise la fonction lexicale prepareNextRound ; on actualise le panneau par surveillance.
  setInterval(function(){
    if(typeof phase!=='undefined'){
      if(phase==='countdown' && document.getElementById('arbRound') && Number(document.getElementById('arbRound').dataset.round||0)!==roundNo){newRoundBoard();document.getElementById('arbRound').dataset.round=String(roundNo);}
      renderRoundBoard();
    }
  },500);
  if(roundNo===0)newRoundBoard();
  renderCoupon();
})();
</script>

<script id="final-reliability-fix">
(function(){
  const ad=document.getElementById("adminOverlay");
  window.closeAdmin=function(){
    const x=document.getElementById("adminOverlay");
    if(x){x.classList.remove("show");x.style.display="none";}
    document.body.style.overflow="auto";
  };
  if(ad){
    ad.addEventListener("click",function(e){if(e.target===ad)window.closeAdmin();});
    const x=ad.querySelector(".funds-head button");
    if(x)x.onclick=window.closeAdmin;
  }
  // All transient messages are explicitly hidden after their timeout.
  ["toast","message"].forEach(id=>{const e=document.getElementById(id);if(e){e.style.pointerEvents="none";}});
})();
</script>

<style>
/* Administration : tableau de comptes lisible sur mobile */
.admin-user-card{border:1px solid #3b392f}
.admin-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
.admin-edit-grid input{width:100%;background:#0e0e0c;color:#fff;border:1px solid #454238;border-radius:7px;padding:9px}
.admin-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.admin-actions button{border:0;border-radius:7px;padding:9px 11px;font-weight:800;cursor:pointer}
@media(max-width:600px){.admin-edit-grid{grid-template-columns:1fr}.admin-actions button{flex:1}}
</style>
<script id="final-request-fix">
(function(){
  // Garantit que les messages temporaires disparaissent après affichage.
  ['toast','message'].forEach(function(id){
    const el=document.getElementById(id); if(!el)return;
    const observer=new MutationObserver(function(){
      if(el.classList.contains('show')){
        clearTimeout(el.__finalHide);
        el.__finalHide=setTimeout(function(){el.classList.remove('show');el.textContent='';},2600);
      }
    });
    observer.observe(el,{attributes:true,attributeFilter:['class'],childList:true,subtree:true});
  });
  // Les boutons X des fenêtres restent toujours prioritaires et fermables.
  document.addEventListener('click',function(e){
    const b=e.target.closest('.close,.keypad-close');
    if(b){e.stopPropagation();}
  },true);
})();
</script>

<script id="coupon-floating-dock">
(function(){
  const style=document.createElement("style");
  style.textContent=`
  #couponFloat{position:fixed;left:0;right:0;bottom:58px;z-index:120;background:#171b20;border-top:1px solid #ffc400;box-shadow:0 -8px 25px rgba(0,0,0,.5);padding:8px 10px;display:none}
  #couponFloat.show{display:block}
  #couponFloatBar{display:flex;align-items:center;gap:7px}
  #couponFloatBar button{border:0;border-radius:8px;padding:9px 10px;font-weight:800;cursor:pointer}
  #couponFloatOpen{background:#ffc400;color:#111;flex:1;text-align:left}
  #couponFloatValidate{background:#22c55e;color:#fff}
  #couponFloatStake{width:105px;background:#0d1217;color:#fff;border:1px solid #3b4652;border-radius:8px;padding:9px;font-weight:800}
  #couponFloatInfo{font-size:11px;color:#b8c1ca;margin-top:5px;text-align:center}
  #couponFloatPanel{display:none;max-height:42vh;overflow:auto;margin-bottom:7px;background:#0f1419;border-radius:10px;padding:8px}
  #couponFloatPanel.open{display:block}
  .cf-pick{display:flex;justify-content:space-between;gap:8px;padding:7px 4px;border-bottom:1px solid #26303a;font-size:11px}
  .cf-pick button{border:0;background:#8b1e1e;color:#fff;border-radius:6px;padding:4px 7px}
  @media(max-width:520px){#couponFloat{bottom:56px}#couponFloatStake{width:88px}}`;
  document.head.appendChild(style);
  const dock=document.createElement("div");dock.id="couponFloat";dock.innerHTML=`<div id="couponFloatPanel"><button id="couponFloatClose" type="button" aria-label="Fermer le coupon">×</button></div><div id="couponFloatBar"><button id="couponFloatOpen">🎫 Coupon <span id="couponFloatCount">0</span></button><input id="couponFloatStake" type="number" min="1" placeholder="Mise"><button id="couponFloatValidate">VALIDER</button></div><div id="couponFloatInfo">Aucun pari sélectionné</div>`;
  document.body.appendChild(dock);
  function render(){
    const panel=document.getElementById("couponFloatPanel"),count=document.getElementById("couponFloatCount"),stake=document.getElementById("couponFloatStake"),info=document.getElementById("couponFloatInfo");
    if(!panel||!count)return;
    const p=window.__bbPicks||[];count.textContent=p.length;dock.classList.toggle("show",p.length>0);
    const mainStake=document.getElementById("stake");
    if(stake.value==="" && mainStake && mainStake.value!=="")stake.value=mainStake.value;
    let total=1;p.forEach(x=>total*=Number(x.o)||1);
    panel.innerHTML=p.length?p.map((x,i)=>`<div class="cf-pick"><span><b>${window.escapeHtml?escapeHtml(x.h):x.h} — ${window.escapeHtml?escapeHtml(x.a):x.a}</b><br>${window.escapeHtml?escapeHtml(x.label):x.label} · ${Number(x.o).toFixed(2)}</span><button data-cf-remove="${i}">✕</button></div>`).join(""):"<div style='text-align:center;color:#888;padding:8px'>Coupon vide</div>";
    const a=Number(stake.value||0);info.textContent=p.length?`Cote totale ${total.toFixed(2)} · Gain potentiel ${a>0?formatMoney(a*total):"—"}`:"Aucun pari sélectionné";
  }
  document.addEventListener("click",e=>{
    if(e.target.closest("#couponFloatClose")){
      e.preventDefault();
      document.getElementById("couponFloatPanel")?.classList.remove("open");
      return;
    }
    if(e.target.closest("#page-sport .coupon-head-close")){
      e.preventDefault();
      const c=document.querySelector("#page-sport .coupon");
      if(c)c.classList.add("is-collapsed");
      return;
    }
    const rm=e.target.closest("[data-cf-remove]");if(rm){e.preventDefault();const i=Number(rm.dataset.cfRemove);if(window.removePickByIndex)window.removePickByIndex(i);return;}
    if(e.target.closest("#couponFloatOpen")){const c=document.querySelector("#page-sport .coupon");if(c)c.classList.remove("is-collapsed");document.getElementById("couponFloatPanel").classList.toggle("open");}
    if(e.target.closest("#couponFloatValidate")){const main=document.getElementById("stake");const fs=document.getElementById("couponFloatStake");if(main&&fs){main.value=fs.value;main.dispatchEvent(new Event("input",{bubbles:true}));}document.getElementById("validateTicket")?.click();}
  },true);
  document.getElementById("couponFloatStake").addEventListener("input",render);
  window.renderCouponFloat=render;

  // ===== Tickets joués des matchs virtuels =====
  window.renderVirtualTicketsPlayed=function(){
    const box=document.getElementById("vtpList");
    if(!box)return;
    const u=window.currentUser&&window.currentUser();
    if(!u){box.innerHTML='<div class="vtp-empty">Connectez-vous pour voir vos tickets.</div>';return;}
    const key="bitcoinBetTickets:"+u.id;
    let tickets=[];try{tickets=JSON.parse((window.bbStorage||localStorage).getItem(key)||"[]")}catch(e){tickets=[]}
    if(!tickets.length){box.innerHTML='<div class="vtp-empty">Aucun ticket joué.</div>';return;}
    box.innerHTML=tickets.slice(0,12).map(t=>{
      const cls=t.status==="Gagné"?"vtp-win":t.status==="Perdu"?"vtp-loss":"vtp-wait";
      const picks=Array.isArray(t.selections)?t.selections.map(s=>`${s.h} — ${s.a} (${s.label})`).join('<br>'):'';
      return `<div class="vtp-ticket"><strong>${escapeHtml(t.id)}</strong> · Mise ${formatMoney(t.stake)} · Cote ${Number(t.totalOdds||1).toFixed(2)}<br><span>${picks}</span><br><span class="vtp-status ${cls}">${escapeHtml(t.status||"En attente")}</span>${t.status==="Gagné"?` · Gain ${formatMoney(t.potentialGain)}`:""}</div>`;
    }).join('');
  };
  window.renderVirtualTicketsPlayed();
  setInterval(()=>{render();window.renderVirtualTicketsPlayed&&window.renderVirtualTicketsPlayed();},1000);
})();
</script>
<script id="virtual-match-engine">
(function(){
  "use strict";

  const teams = [
    ["Real Madrid","Manchester City"],
    ["Bayern Munich","Paris Saint-Germain"],
    ["FC Barcelona","Liverpool"],
    ["Inter Milan","Arsenal"],
    ["Borussia Dortmund","Atlético Madrid"],
    ["AC Milan","Chelsea"]
  ];

  const VIRTUAL_MATCH_KEY = "bitcoinBetVirtualMatchesV2";
  const PRESTART = 45;
  const MATCH_SECONDS = 90;
  const BREAK_SECONDS = 12;

  let virtualMatches = [];
  let liveMatchId = null;
  let liveTimer = null;
  let liveOpened = false;

  function vm(id){return document.getElementById(id);}
  function vmEsc(v){
    return String(v??"").replace(/[&<>"']/g,m=>({
      "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
    }[m]));
  }

  function seededScore(){
    return Math.random();
  }

  function makeMatches(){
    const now=Date.now();
    virtualMatches=teams.map((t,i)=>({
      id:"VM-"+i,
      home:t[0],
      away:t[1],
      created:now+i*1000,
      phase:"waiting",
      remaining:PRESTART,
      elapsed:0,
      homeScore:0,
      awayScore:0,
      shotsH:0,
      shotsA:0,
      attacksH:0,
      attacksA:0,
      cornersH:0,
      cornersA:0,
      possessionH:50,
      possessionA:50,
      lastEvent:"Coup d’envoi en préparation",
      events:[],
      odds:{h:(2.05+seededScore()*1.05),n:(3.10+seededScore()*1.35),a:(2.05+seededScore()*1.05)}
    }));
  }

  function saveVM(){
    try{bbStorage.setItem(VIRTUAL_MATCH_KEY,JSON.stringify(virtualMatches));}catch(e){}
  }

  function loadVM(){
    let data=null;
    try{data=JSON.parse(bbStorage.getItem(VIRTUAL_MATCH_KEY)||"null");}catch(e){}
    if(Array.isArray(data)&&data.length===teams.length){
      virtualMatches=data;
    }else{
      makeMatches();
      saveVM();
    }

    // Repartir proprement si un ancien prototype a été conservé.
    virtualMatches.forEach((m,i)=>{
      if(!m.id)m.id="VM-"+i;
      if(!["waiting","live","finished"].includes(m.phase))m.phase="waiting";
      if(!Number.isFinite(Number(m.remaining)))m.remaining=PRESTART;
      if(!Number.isFinite(Number(m.elapsed)))m.elapsed=0;
      if(!Array.isArray(m.events))m.events=[];
      if(m.phase==="waiting" && Number(m.remaining)<=0)m.remaining=PRESTART;
      if(m.phase==="live" && Number(m.elapsed)>=MATCH_SECONDS){m.phase="finished";m.elapsed=MATCH_SECONDS;}
    });
  }

  function formatClock(sec){
    sec=Math.max(0,Math.floor(Number(sec)||0));
    const m=Math.floor(sec/60),s=sec%60;
    return String(m).padStart(2,"0")+":"+String(s).padStart(2,"0");
  }

  function statusText(m){
    if(m.phase==="waiting")return "⏳ DÉBUT DANS "+Math.ceil(m.remaining)+"s";
    if(m.phase==="live")return "🔴 EN DIRECT";
    return "🏁 TERMINÉ";
  }

  function renderVirtualMatches(){
    const box=vm("virtualMatches");
    if(!box)return;

    const q=(vm("search")?.value||"").toLowerCase().trim();

    box.innerHTML=virtualMatches
      .filter(m=>(m.home+" "+m.away).toLowerCase().includes(q))
      .map(m=>{
        const progress=m.phase==="waiting"
          ? Math.max(0,Math.min(100,(PRESTART-m.remaining)/PRESTART*100))
          : Math.max(0,Math.min(100,m.elapsed/MATCH_SECONDS*100));

        const disabled=m.phase==="finished" ? "disabled" : "";
        const hint=m.phase==="waiting"
          ? "Cliquez sur le match pour suivre le direct"
          : m.phase==="live"
            ? "🔴 Match en direct — vous pouvez encore miser"
            : "🏁 Terminé — prochain coup d’envoi dans "+Math.ceil(m.remaining)+"s";

        return `
        <div class="virtual-match" data-vmid="${vmEsc(m.id)}">
          <div class="vm-top">
            <span class="vm-status ${m.phase}">${statusText(m)}</span>
            <span class="vm-clock">${m.phase==="waiting" ? "Début dans "+Math.ceil(m.remaining)+"s" : m.phase==="live" ? "Temps : "+formatClock(m.elapsed) : "Prochain départ : "+Math.ceil(m.remaining)+"s"}</span>
          </div>

          <div class="vm-teams">
            <div class="vm-team">${vmEsc(m.home)}</div>
            <div class="vm-score">${m.homeScore} - ${m.awayScore}<small>${m.phase==="live" ? "DIRECT" : "VIRTUEL"}</small></div>
            <div class="vm-team">${vmEsc(m.away)}</div>
          </div>

          <div class="vm-odds">
            <button class="vm-odd" data-vmid="${vmEsc(m.id)}" data-vpick="1" ${disabled}>
              <span>1</span><b>${m.odds.h.toFixed(2)}</b>
            </button>
            <button class="vm-odd" data-vmid="${vmEsc(m.id)}" data-vpick="N" ${disabled}>
              <span>NUL</span><b>${m.odds.n.toFixed(2)}</b>
            </button>
            <button class="vm-odd" data-vmid="${vmEsc(m.id)}" data-vpick="2" ${disabled}>
              <span>2</span><b>${m.odds.a.toFixed(2)}</b>
            </button>
          </div>

          <div class="vm-progress"><i style="width:${progress}%"></i></div>
          <div class="vm-event">${vmEsc(m.lastEvent||"")}</div>
          <div class="vm-hint">${hint}</div>
        </div>`;
      }).join("");

    if(!box.innerHTML){
      box.innerHTML='<div class="empty">Aucun match virtuel trouvé.</div>';
    }
  }

  function findMatch(id){
    return virtualMatches.find(x=>x.id===id);
  }

  function addEvent(m,text){
    const minute=m.phase==="live"
      ? Math.max(1,Math.ceil(m.elapsed/2))
      : 0;
    m.lastEvent=text;
    m.events.unshift({
      minute,
      text,
      time:new Date().toLocaleTimeString("fr-FR",{hour:"2-digit",minute:"2-digit",second:"2-digit"})
    });
    m.events=m.events.slice(0,15);
  }

  function startMatch(m){
    m.phase="live";
    m.elapsed=0;
    m.remaining=0;
    m.lastEvent="⚽ Coup d’envoi !";
    addEvent(m,"⚽ Coup d’envoi !");
    // Les cotes deviennent légèrement dynamiques en direct.
    m.odds.h=Math.max(1.15,m.odds.h*0.94);
    m.odds.n=Math.max(2.30,m.odds.n*0.99);
    m.odds.a=Math.max(1.15,m.odds.a*0.94);
  }


  function settleVirtualTickets(m){
    try{
      const u=window.currentUser&&window.currentUser();
      if(!u)return;
      const key="bitcoinBetTickets:"+u.id;
      const tickets=JSON.parse((window.bbStorage||localStorage).getItem(key)||"[]");
      let changed=false,credited=0;
      tickets.forEach(t=>{
        if(t.status!=="En attente"||!Array.isArray(t.selections)||!t.selections.length)return;
        let hasLost=false,allResolved=true,hasVirtual=false;
        t.selections.forEach(s=>{
          if(!s.vmId){allResolved=false;return;}
          hasVirtual=true;
          const vm=findMatch(s.vmId);
          if(!vm||vm.phase!=="finished"){allResolved=false;return;}
          const result=vm.homeScore>vm.awayScore?"1":vm.homeScore<vm.awayScore?"2":"N";
          const won=(s.label===result)||(s.label==="Nul"&&result==="N");
          s.result=result;
          s.outcome=won?"Gagné":"Perdu";
          if(!won)hasLost=true;
        });
        if(!hasVirtual)return;
        if(hasLost){
          t.status="Perdu";
          changed=true;
        }else if(allResolved){
          t.status="Gagné";
          const gain=Number(t.potentialGain)||0;
          balance+=gain;
          credited+=gain;
          changed=true;
        }
      });
      if(changed){
        (window.bbStorage||localStorage).setItem(key,JSON.stringify(tickets));
        updateBalance();
        if(typeof window.renderVirtualTicketsPlayed==='function')window.renderVirtualTicketsPlayed();
        if(credited>0)toast("Ticket gagnant : +"+formatMoney(credited));
        else toast("Résultat du ticket mis à jour");
      }
    }catch(e){console.error("settleVirtualTickets",e);}
  }

  function finishMatch(m){
    m.phase="finished";
    m.elapsed=MATCH_SECONDS;
    m.remaining=BREAK_SECONDS;
    m.lastEvent="🏁 Fin du match — prochain coup d’envoi dans "+BREAK_SECONDS+"s";
    addEvent(m,"🏁 Fin du match");
    settleVirtualTickets(m);
  }

  function simulateLive(m){
    if(m.phase!=="live")return;

    m.elapsed++;
    m.attacksH+=Math.random()<.62?1:0;
    m.attacksA+=Math.random()<.58?1:0;

    if(Math.random()<.36)m.shotsH++;
    if(Math.random()<.33)m.shotsA++;

    if(Math.random()<.08)m.cornersH++;
    if(Math.random()<.08)m.cornersA++;

    m.possessionH=Math.max(35,Math.min(65,m.possessionH+(Math.random()-.5)*3));
    m.possessionA=100-m.possessionH;

    // Événements virtuels : buts, cartons, occasions.
    const goalChance=0.018;

    if(Math.random()<goalChance){
      const homeGoal=Math.random()<.5;
      if(homeGoal)m.homeScore++;else m.awayScore++;
      addEvent(m,"⚽ BUT — "+(homeGoal?m.home:m.away));
      m.lastEvent="⚽ BUT ! "+(homeGoal?m.home:m.away);
    }else if(Math.random()<0.018){
      const side=Math.random()<.5?m.home:m.away;
      addEvent(m,"🟨 Carton jaune — "+side);
      m.lastEvent="🟨 Carton jaune";
    }else if(Math.random()<0.055){
      const side=Math.random()<.5?m.home:m.away;
      addEvent(m,"🔥 Occasion dangereuse — "+side);
      m.lastEvent="🔥 Occasion dangereuse";
    }

    // Ajustement léger des cotes virtuelles.
    const diff=m.homeScore-m.awayScore;
    m.odds.h=Math.max(1.05,2.8-diff*.38-(m.elapsed/150));
    m.odds.a=Math.max(1.05,2.8+diff*.38-(m.elapsed/170));
    m.odds.n=Math.max(2.05,3.55-Math.abs(diff)*.15-(m.elapsed/300));

    if(m.elapsed>=MATCH_SECONDS){
      finishMatch(m);

    }
  }

  function tickVirtualMatches(){
    virtualMatches.forEach(m=>{
      if(m.phase==="waiting"){
        m.remaining=Math.max(0,m.remaining-1);
        if(m.remaining<=0)startMatch(m);
      }else if(m.phase==="live"){
        simulateLive(m);
      }else if(m.phase==="finished"){
        m.remaining=Math.max(0,Number(m.remaining||0)-1);
        m.lastEvent="🏁 Fin du match — prochain coup d’envoi dans "+m.remaining+"s";
        if(m.remaining<=0){
          m.phase="waiting";
          m.remaining=PRESTART;
          m.elapsed=0;
          m.homeScore=0;m.awayScore=0;
          m.shotsH=0;m.shotsA=0;m.attacksH=0;m.attacksA=0;
          m.cornersH=0;m.cornersA=0;m.possessionH=50;m.possessionA=50;
          m.events=[];
          m.lastEvent="⏳ Nouveau match virtuel en préparation";
          m.odds={h:2.05+Math.random()*1.05,n:3.10+Math.random()*1.35,a:2.05+Math.random()*1.05};
        }
      }
    });

    saveVM();
    renderVirtualMatches();

    if(liveMatchId){
      const m=findMatch(liveMatchId);
      if(m)renderLive(m);
    }
  }

  function openVirtualLive(id){
    const m=findMatch(id);
    if(!m)return;

    liveMatchId=id;
    liveOpened=true;

    const o=vm("virtualLiveOverlay");
    if(o){
      o.classList.add("show");
      o.style.display="flex";
      o.setAttribute("aria-hidden","false");
    }

    renderLive(m);
  }

  function closeVirtualLive(){
    const o=vm("virtualLiveOverlay");
    if(o){
      o.classList.remove("show");
      o.style.display="none";
      o.setAttribute("aria-hidden","true");
    }
    liveOpened=false;
    liveMatchId=null;
  }

  function renderLive(m){
    if(!m)return;

    vm("vliveHome").textContent=m.home;
    vm("vliveAway").textContent=m.away;
    vm("vliveScore").textContent=m.homeScore+" - "+m.awayScore;

    if(m.phase==="waiting"){
      vm("vliveMinute").textContent="AVANT MATCH";
      vm("vliveCountdown").textContent="COUP D’ENVOI DANS "+Math.ceil(m.remaining)+"s";
      vm("vliveBadge").textContent="⏳ MATCH VIRTUEL";
    }else if(m.phase==="live"){
      vm("vliveMinute").textContent="MINUTE "+Math.min(90,Math.ceil(m.elapsed/2));
      vm("vliveCountdown").textContent="🔴 MATCH EN DIRECT";
      vm("vliveBadge").textContent="🔴 DIRECT VIRTUEL";
    }else{
      vm("vliveMinute").textContent="FIN DU MATCH";
      vm("vliveCountdown").textContent="🏁 MATCH TERMINÉ";
      vm("vliveBadge").textContent="🏁 TERMINÉ";
    }

    vm("vlivePoss").textContent=
      Math.round(m.possessionH)+"% — "+Math.round(m.possessionA)+"%";

    vm("vliveShots").textContent=m.shotsH+" — "+m.shotsA;
    vm("vliveAttacks").textContent=m.attacksH+" — "+m.attacksA;
    vm("vliveCorners").textContent=m.cornersH+" — "+m.cornersA;

    const ball=vm("vliveBall");
    if(ball){
      ball.style.left=(25+Math.random()*50)+"%";
      ball.style.top=(25+Math.random()*50)+"%";
    }

    const events=vm("vliveEvents");
    if(events){
      if(!m.events.length){
        events.innerHTML='<div class="vlive-event"><b>INFO</b><span>En attente du coup d’envoi virtuel.</span></div>';
      }else{
        events.innerHTML=m.events.slice(0,8).map(e=>
          '<div class="vlive-event"><b>'+
          (e.minute?e.minute+"'":"INFO")+
          '</b><span>'+vmEsc(e.text)+'</span></div>'
        ).join("");
      }
    }

    vm("liveOdd1").textContent=m.odds.h.toFixed(2);
    vm("liveOddN").textContent=m.odds.n.toFixed(2);
    vm("liveOdd2").textContent=m.odds.a.toFixed(2);

    const ended=vm("vliveEnded");
    if(ended)ended.style.display=m.phase==="finished"?"block":"none";
  }

  // Ouvrir le direct en cliquant sur une carte, mais pas quand on clique sur une cote.
  document.addEventListener("click",function(e){
    const odd=e.target.closest(".vm-odd");
    if(odd){
      e.preventDefault();
      e.stopPropagation();

      if(odd.disabled){
        window.toast&&window.toast("Les cotes 1X2 sont fermées après le coup d’envoi.");
        return;
      }

      const m=findMatch(odd.dataset.vmid);
      if(!m)return;

      const pick=odd.dataset.vpick;
      const label=pick==="1"?"1":pick==="2"?"2":"Nul";
      const oddValue=pick==="1"?m.odds.h:pick==="2"?m.odds.a:m.odds.n;

      if(typeof window.add==="function"){
        window.add(m.home,m.away,"1X2",label,Number(oddValue.toFixed(2)),m.id);
        window.toast&&window.toast(m.phase==="live" ? "Mise en direct ajoutée au coupon" : "Mise ajoutée au coupon");
      }
      return;
    }

    const card=e.target.closest(".virtual-match");
    if(card){
      e.preventDefault();
      e.stopPropagation();
      openVirtualLive(card.dataset.vmid);
      return;
    }

    const liveMarket=e.target.closest(".vlive-market");
    if(liveMarket){
      const m=findMatch(liveMatchId);
      if(!m)return;

      const pick=liveMarket.dataset.liveMarket;
      const label=pick==="1"?"1":pick==="2"?"2":"Nul";
      const oddValue=pick==="1"?m.odds.h:pick==="2"?m.odds.a:m.odds.n;

      if(m.phase==="finished"){
        window.toast&&window.toast("Le match est terminé : marché fermé.");
        return;
      }

      if(typeof window.add==="function"){
        window.add(m.home,m.away,"1X2",label,Number(oddValue.toFixed(2)),m.id);
        window.toast&&window.toast(m.phase==="live" ? "Mise en direct ajoutée au coupon" : "Mise ajoutée au coupon");
      }
    }
  },true);

  // Empêcher les anciens gestionnaires .match de perturber le moteur virtuel.
  document.addEventListener("click",function(e){
    const vmc=e.target.closest(".virtual-match");
    if(vmc)e.stopImmediatePropagation();
  },false);

  // Recherche.
  const search=vm("search");
  if(search)search.addEventListener("input",renderVirtualMatches);

  window.closeVirtualLive=closeVirtualLive;
  window.clearVirtualCoupon=function(){
    if(typeof window.clearCoupon==="function"){
      window.clearCoupon();
    }else if(typeof window.cancelCoupon==="function"){
      window.cancelCoupon();
    }
  };

  loadVM();
  renderVirtualMatches();

  // Boucle virtuelle : 1 seconde = 1 seconde de simulation.
  clearInterval(window.__virtualMatchInterval);
  window.__virtualMatchInterval=setInterval(tickVirtualMatches,1000);

  // Fermer le direct avec Échap ou clic extérieur.
  const overlay=vm("virtualLiveOverlay");
  if(overlay){
    overlay.addEventListener("click",function(e){
      if(e.target===overlay)closeVirtualLive();
    });
  }

  document.addEventListener("keydown",function(e){
    if(e.key==="Escape" && liveOpened)closeVirtualLive();
  });

})();
</script>


<script id="php-mysql-server-integration">
(function(){
  "use strict";
  const $=id=>document.getElementById(id);
  const api=async(action,data={})=>{
    const r=await fetch(location.pathname+"?action="+encodeURIComponent(action),{
      method:"POST",headers:{"Content-Type":"application/json"},credentials:"same-origin",
      body:JSON.stringify(data)
    });
    const j=await r.json().catch(()=>({ok:false,error:"Réponse serveur invalide"}));
    if(!r.ok||j.ok===false)throw new Error(j.error||"Erreur serveur");
    return j;
  };
  const refresh=async()=>{
    try{
      const r=await fetch(location.pathname+"?action=bootstrap",{credentials:"same-origin"});
      const j=await r.json();
      if(j.ok&&j.data){
        Object.keys(j.data).forEach(k=>window.__BB_BOOTSTRAP[k]=j.data[k]);
        if(j.data.bitcoinBetCurrentUser){
          window.bbStorage.setItem("bitcoinBetCurrentUser",j.data.bitcoinBetCurrentUser);
          window.balance=Number(j.data.bitcoinBetBalance)||10000;
          if(typeof window.updateBalance==="function")window.updateBalance();
          if(typeof window.renderAdmin==="function")window.renderAdmin();
        }
      }
    }catch(e){}
  };

  async function serverRegister(){
    const name=($("regName")?.value||"").trim();
    const username=($("regUser")?.value||"").trim().toLowerCase();
    const phone=($("regPhone")?.value||"").trim();
    const pass=$("regPass")?.value||"", pass2=$("regPass2")?.value||"";
    const msg=(t,ok)=>{const e=$("registerMsg");if(e){e.textContent=t;e.className="auth-msg"+(ok?" ok":"");}};
    if(name.length<2)return msg("Entrez votre nom complet.");
    if(username.length<3)return msg("Nom d’utilisateur : 3 caractères minimum.");
    if(!/^[a-z0-9_.-]+$/i.test(username))return msg("Nom d’utilisateur invalide.");
    if(!phone)return msg("Entrez votre numéro de téléphone.");
    if(pass.length<4)return msg("Mot de passe : 4 caractères minimum.");
    if(pass!==pass2)return msg("Les deux mots de passe sont différents.");
    try{
      const j=await api("register",{name,username,phone,password:pass});
      window.__BB_BOOTSTRAP.bitcoinBetCurrentUser=j.user.id;
      window.__BB_BOOTSTRAP.bitcoinBetBalance=String(j.user.balance);
      window.bbStorage.setItem("bitcoinBetCurrentUser",j.user.id);
      window.bbStorage.setItem("bitcoinBetBalance",String(j.user.balance));
      window.balance=Number(j.user.balance)||10000;
      if(typeof window.updateBalance==="function")window.updateBalance();
      if(typeof window.updateCurrentUserLabel==="function")window.updateCurrentUserLabel();
      const e=$("registerMsg");if(e){e.textContent="Compte créé avec succès. Bienvenue !";e.className="auth-msg ok";}
      setTimeout(()=>location.reload(),250);
    }catch(e){msg(e.message);}
  }

  async function serverLogin(){
    const username=($("loginUser")?.value||"").trim().toLowerCase();
    const pass=$("loginPass")?.value||"";
    const msg=(t,ok)=>{const e=$("loginMsg");if(e){e.textContent=t;e.className="auth-msg"+(ok?" ok":"");}};
    if(!username||!pass)return msg("Entrez votre nom d’utilisateur et votre mot de passe.");
    try{
      const j=await api("login",{username,password:pass});
      window.__BB_BOOTSTRAP.bitcoinBetCurrentUser=j.user.id;
      window.__BB_BOOTSTRAP.bitcoinBetBalance=String(j.user.balance);
      window.bbStorage.setItem("bitcoinBetCurrentUser",j.user.id);
      window.bbStorage.setItem("bitcoinBetBalance",String(j.user.balance));
      window.balance=Number(j.user.balance)||10000;
      if(typeof window.updateBalance==="function")window.updateBalance();
      if(typeof window.updateCurrentUserLabel==="function")window.updateCurrentUserLabel();
      if(typeof window.showPage==="function")window.showPage("sport");
      const o=$("authOverlay");if(o){o.classList.remove("show","hidden");o.style.display="none";}
      document.body.style.overflow="auto";
      if(typeof window.toast==="function")window.toast("Connexion réussie");
    }catch(e){msg(e.message);}
  }

  async function serverLogout(){
    try{await api("logout",{});}catch(e){}
    window.bbStorage.removeItem("bitcoinBetCurrentUser");
    window.bbStorage.removeItem("bitcoinBetBalance");
    if(typeof window.showAuth==="function")window.showAuth("login");
    if(typeof window.openAuth==="function")window.openAuth();
  }

  async function serverAdminLogin(){
    const username=($("adminUsernameInput")?.value||"admin").trim().toLowerCase();
    const p=$("adminPasswordInput")?.value||"";
    try{
      await api("admin_login",{username,password:p});
      if($("adminPasswordInput"))$("adminPasswordInput").value="";
      if($("adminLoginBox"))$("adminLoginBox").style.display="none";
      if($("adminBox"))$("adminBox").style.display="block";
      if($("adminOverlay"))$("adminOverlay").classList.add("show");
      if(typeof window.renderAdmin==="function")window.renderAdmin();
      if(typeof window.toast==="function")window.toast("Administration ouverte");
    }catch(e){if(typeof window.toast==="function")window.toast(e.message);}
  }
  async function serverAdminLogout(){
    try{await api("admin_logout",{});}catch(e){}
    if($("adminBox"))$("adminBox").style.display="none";
    if($("adminLoginBox"))$("adminLoginBox").style.display="block";
  }
  async function changeAdminPassword(){
    const current=$("adminCurrentPassword")?.value||"";
    const next=$("adminNewPassword")?.value||"";
    if(next.length<8){if(typeof window.toast==='function')window.toast("Le nouveau mot de passe doit contenir au moins 8 caractères.");return;}
    try{
      const r=await api("admin_change_password",{current_password:current,new_password:next});
      if($("adminCurrentPassword"))$("adminCurrentPassword").value="";
      if($("adminNewPassword"))$("adminNewPassword").value="";
      if(typeof window.toast==='function')window.toast(r.message||"Mot de passe modifié.");
    }catch(e){if(typeof window.toast==='function')window.toast(e.message);}
  }
  async function serverOpenAdmin(){
    try{
      const r=await api("session",{});
      const logged=!!r.admin;
      if($("adminLoginBox"))$("adminLoginBox").style.display=logged?"none":"block";
      if($("adminBox"))$("adminBox").style.display=logged?"block":"none";
      const ad=$("adminOverlay");if(ad){ad.classList.add("show");ad.style.display="flex";ad.style.zIndex="10000";}
      if(logged&&typeof window.renderAdmin==='function')window.renderAdmin();
    }catch(e){if(typeof window.toast==='function')window.toast(e.message);}
  }

  async function serverLoadStorageKey(key){
    const r=await fetch(location.pathname+"?action=storage&key="+encodeURIComponent(key),{credentials:"same-origin"});
    const j=await r.json();
    if(j.ok){
      if(j.value===null) window.bbStorage.removeItem(key); else window.bbStorage.setItem(key,j.value);
    }
    return j.value;
  }
  async function serverSubmitWalletRequest(type){
    const op=$(type+"Operator")?.value||"";
    const phone=$(type+"Phone")?.value.trim()||"";
    const amount=Number($(type+"Amount")?.value);
    if(!phone){toast("Entrez le numéro Mobile Money");return;}
    if(!Number.isFinite(amount)||amount<100){toast("Montant minimum : 100 FCFA");return;}
    if(type==="withdraw" && amount>Number(window.balance||0)){toast("Solde insuffisant");return;}
    try{
      await api("wallet_create",{type,amount,operator:op,phone});
      if($(type+"Phone"))$(type+"Phone").value="";
      if($(type+"Amount"))$(type+"Amount").value="";
      await serverLoadStorageKey("bitcoinBetWalletRequests");
      if(typeof window.renderWalletRequests==="function")window.renderWalletRequests();
      if(typeof window.renderFunds==="function")window.renderFunds();
      toast("Demande envoyée à l’administrateur");
    }catch(e){toast(e.message);}
  }
  async function serverDecideWallet(id,decision){
    try{
      await api("wallet_decide",{id,decision});
      await serverLoadStorageKey("bitcoinBetWalletRequests");
      await serverLoadStorageKey("bitcoinBetUsers");
      const session=await api("session",{});
      if(session.user){
        window.balance=Number(session.user.balance)||0;
        window.bbStorage.setItem("bitcoinBetBalance",String(window.balance));
        if(typeof window.updateBalance==="function")window.updateBalance();
      }
      if(typeof window.renderAdmin==="function")window.renderAdmin();
      if(typeof window.renderWalletRequests==="function")window.renderWalletRequests();
      toast(decision==="approved"?"Demande approuvée":"Demande rejetée");
    }catch(e){toast(e.message);}
  }
  async function serverAdminEditUser(id){
    const name=$("an-"+id)?.value.trim()||"";
    const username=$("au-"+id)?.value.trim().toLowerCase()||"";
    const phone=$("ap-"+id)?.value.trim()||"";
    const balance=Number($("ab-"+id)?.value);
    if(!name||!username||!Number.isFinite(balance)||balance<0){toast("Informations utilisateur invalides");return;}
    try{
      await api("user_update",{id,name,username,phone,balance});
      await serverLoadStorageKey("bitcoinBetUsers");
      if(String(window.bbStorage.getItem("bitcoinBetCurrentUser"))===String(id)){
        window.balance=balance; if(typeof window.updateBalance==="function")window.updateBalance();
      }
      if(typeof window.renderAdmin==="function")window.renderAdmin();
      toast("Compte modifié");
    }catch(e){toast(e.message);}
  }
  async function serverAdminDeleteUser(id){
    if(!confirm("Supprimer définitivement ce compte ? Cette action supprimera aussi ses tickets."))return;
    try{
      await api("wallet_delete_user",{id});
      await serverLoadStorageKey("bitcoinBetUsers");
      await serverLoadStorageKey("bitcoinBetWalletRequests");
      if(String(window.bbStorage.getItem("bitcoinBetCurrentUser"))===String(id)){
        await api("logout",{});
        window.bbStorage.removeItem("bitcoinBetCurrentUser");
        window.bbStorage.removeItem("bitcoinBetBalance");
        window.balance=10000;
      }
      if(typeof window.renderAdmin==="function")window.renderAdmin();
      if(typeof window.updateCurrentUserLabel==="function")window.updateCurrentUserLabel();
      toast("Compte supprimé");
    }catch(e){toast(e.message);}
  }

  window.submitWalletRequest=serverSubmitWalletRequest;
  window.decideWallet=serverDecideWallet;
  window.adminEditUser=serverAdminEditUser;
  window.adminDeleteUser=serverAdminDeleteUser;

  window.registerUser=serverRegister;
  window.loginUser=serverLogin;
  window.logoutUser=serverLogout;
  window.adminLogin=serverAdminLogin;
  window.adminLogout=serverAdminLogout;
  window.changeAdminPassword=changeAdminPassword;
  window.openAdmin=serverOpenAdmin;

  // Synchronise le solde serveur après les opérations de jeu.
  const oldUpdate=window.updateBalance;
  if(typeof oldUpdate==="function"){
    window.updateBalance=function(){
      oldUpdate.apply(this,arguments);
      const u=window.currentUser&&window.currentUser();
      if(u){
        api("balance",{balance:Number(window.balance)||0}).catch(()=>{});
      }
    };
  }

  // Au chargement, la session PHP est la source de vérité.
  (async()=>{
    await refresh();
    try{
      const r=await fetch(location.pathname+"?action=session",{credentials:"same-origin"});
      const j=await r.json();
      if(j.ok&&j.user){
        window.__BB_BOOTSTRAP.bitcoinBetCurrentUser=j.user.id;
        window.__BB_BOOTSTRAP.bitcoinBetBalance=String(j.user.balance);
        window.bbStorage.setItem("bitcoinBetCurrentUser",j.user.id);
        window.bbStorage.setItem("bitcoinBetBalance",String(j.user.balance));
        window.balance=Number(j.user.balance)||10000;
        if(typeof window.updateBalance==="function")window.updateBalance();
        if(typeof window.showPage==="function")window.showPage("sport");
        const o=$("authOverlay");if(o){o.classList.remove("show","hidden");o.style.display="none";}
      }else{
        if(typeof window.showAuth==="function")window.showAuth("login");
        if(typeof window.openAuth==="function")window.openAuth();
      }
    }catch(e){}
  })();
})();
</script>
</body>
</html>