<?php
// ------------------------------------------------------------
// FUNÇÕES COMUNS AOS FORMULÁRIOS DO SITE (queroConhecer / faleConosco)
// Compatível com PHP 7.4+
// ------------------------------------------------------------

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\OAuth;
use League\OAuth2\Client\Provider\Google;

require_once __DIR__ . '/../vendor/autoload.php';

const HM_ERP_BASE_URL = 'https://urpldjdpivljaxuibihw.supabase.co/functions/v1/';

// Domínios que podem enviar dados pelo navegador (CORS).
// Pode ser trocado pela chave "allowedOrigins" do arquivo de configuração.
const HM_ORIGENS_PADRAO = [
    'https://www.helpmedico.com.br',
    'https://helpmedico.com.br',
    'https://*.lovable.app',
    'https://*.lovableproject.com',
];

/**
 * Responde em JSON e encerra.
 */
function hm_responder(int $status, bool $sucesso, string $mensagem): void
{
    http_response_code($status);
    echo json_encode(['resposta' => $mensagem, 'sucesso' => $sucesso], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Lê o arquivo de configuração. Devolve [] se não existir ou for inválido.
 */
function hm_carregar_config(string $arquivo): array
{
    if (!is_file($arquivo)) {
        error_log("Arquivo de configuração não encontrado: $arquivo");
        return [];
    }
    $config = json_decode((string) file_get_contents($arquivo), true);
    if (!is_array($config)) {
        error_log("Arquivo de configuração inválido: $arquivo");
        return [];
    }
    return $config;
}

/**
 * Cabeçalhos CORS para os domínios autorizados e tratamento do pré-voo (OPTIONS).
 * Também recusa qualquer método diferente de POST.
 */
function hm_cors(array $config): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Vary: Origin');

    $origem    = $_SERVER['HTTP_ORIGIN'] ?? '';
    $permitidas = (isset($config['allowedOrigins']) && is_array($config['allowedOrigins']))
        ? $config['allowedOrigins']
        : HM_ORIGENS_PADRAO;

    if ($origem !== '' && hm_origem_permitida($origem, $permitidas)) {
        header("Access-Control-Allow-Origin: $origem");
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }

    $metodo = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($metodo === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($metodo !== 'POST') {
        header('Allow: POST, OPTIONS');
        hm_responder(405, false, 'Método não permitido');
    }
}

function hm_origem_permitida(string $origem, array $permitidas): bool
{
    foreach ($permitidas as $padrao) {
        $padrao = rtrim((string) $padrao, '/');
        if (strcasecmp($origem, $padrao) === 0) {
            return true;
        }
        // Curinga apenas no subdomínio: https://*.exemplo.com
        if (strpos($padrao, '*.') !== false) {
            $regex = '#^' . str_replace('\*', '[a-z0-9-]+(\.[a-z0-9-]+)*', preg_quote($padrao, '#')) . '$#i';
            if (preg_match($regex, $origem)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Lê o corpo da requisição (JSON ou formulário tradicional).
 */
function hm_ler_dados(): array
{
    $dados = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($dados)) {
        $dados = $_POST;
    }
    return is_array($dados) ? $dados : [];
}

/**
 * Limpa um texto: tira espaços nas pontas, caracteres de controle e limita o tamanho.
 * Em campos de uma linha, quebras de linha viram espaço (evita injeção de cabeçalhos).
 */
function hm_texto($valor, int $max, bool $multilinha = false): string
{
    if (!is_scalar($valor)) {
        return '';
    }
    $texto = (string) $valor;
    if (!mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
    }
    $texto = $multilinha
        ? preg_replace('/[^\P{C}\n\t]/u', '', str_replace(["\r\n", "\r"], "\n", $texto))
        : preg_replace('/\p{C}+/u', ' ', $texto);
    $texto = trim(preg_replace('/[ \t]{2,}/', ' ', (string) $texto));
    return mb_substr($texto, 0, $max);
}

/**
 * Valida os campos. $regras = ['campo' => ['rotulo', obrigatorio, tamanhoMax, multilinha]].
 * Devolve [dadosLimpos, mensagemDeErro|null].
 */
function hm_validar(array $entrada, array $regras): array
{
    $limpos = [];
    foreach ($regras as $campo => [$rotulo, $obrigatorio, $max, $multilinha]) {
        $valor = hm_texto($entrada[$campo] ?? '', $max, $multilinha);
        if ($obrigatorio && $valor === '') {
            return [$limpos, "Preencha o campo $rotulo."];
        }
        $limpos[$campo] = $valor;
    }

    if (isset($limpos['email']) && $limpos['email'] !== '' && !filter_var($limpos['email'], FILTER_VALIDATE_EMAIL)) {
        return [$limpos, 'Informe um e-mail válido.'];
    }
    if (isset($limpos['phone']) && $limpos['phone'] !== '') {
        $digitos = strlen(preg_replace('/\D/', '', $limpos['phone']));
        if ($digitos < 10 || $digitos > 13) {
            return [$limpos, 'Informe um telefone válido, com DDD.'];
        }
    }
    if (isset($limpos['name']) && $limpos['name'] !== '' && mb_strlen($limpos['name']) < 3) {
        return [$limpos, 'Informe seu nome completo.'];
    }
    if (isset($limpos['crm']) && $limpos['crm'] !== '' && !preg_match('/\d{3,}/', $limpos['crm'])) {
        return [$limpos, 'Informe um CRM válido.'];
    }
    return [$limpos, null];
}

/**
 * Campo-armadilha para robôs: se vier preenchido, é spam.
 */
function hm_eh_robo(array $entrada): bool
{
    foreach (['website', 'empresa_site', 'url'] as $campo) {
        if (!empty($entrada[$campo])) {
            return true;
        }
    }
    return false;
}

function hm_ip_cliente(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return trim($_SERVER['HTTP_CLIENT_IP']);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Pasta de dados (backups e controle de envios), protegida contra acesso pela web.
 * Pode ser trocada pela chave "dataDir" da configuração (de preferência fora da pasta pública).
 */
function hm_pasta_dados(array $config): string
{
    $pasta = !empty($config['dataDir']) ? rtrim($config['dataDir'], '/\\') : dirname(__DIR__) . '/dados';
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0750, true);
    }
    $htaccess = $pasta . '/.htaccess';
    if (is_dir($pasta) && !is_file($htaccess)) {
        @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
    $index = $pasta . '/index.html';
    if (is_dir($pasta) && !is_file($index)) {
        @file_put_contents($index, '');
    }
    return $pasta;
}

/**
 * Limite de envios por IP: no máximo $max envios a cada $janela segundos, por formulário.
 */
function hm_excedeu_limite(string $pasta, string $formulario, string $ip, int $max, int $janela): bool
{
    if ($ip === '' || $max <= 0) {
        return false;
    }
    $dir = $pasta . '/limite';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $arquivo = $dir . '/' . $formulario . '-' . hash('sha256', $ip) . '.json';
    $fp = @fopen($arquivo, 'c+');
    if (!$fp) {
        return false; // sem controle não bloqueia o visitante
    }
    flock($fp, LOCK_EX);
    $agora    = time();
    $envios   = json_decode((string) stream_get_contents($fp), true);
    $envios   = array_values(array_filter(is_array($envios) ? $envios : [], function ($t) use ($agora, $janela) {
        return is_int($t) && $t > $agora - $janela;
    }));
    $excedeu = count($envios) >= $max;
    if (!$excedeu) {
        $envios[] = $agora;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($envios));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $excedeu;
}

/**
 * Grava (ou regrava) o backup do envio. Devolve o caminho do arquivo ou '' em caso de falha.
 */
function hm_salvar_backup(string $pasta, array $registro, string $arquivo = ''): string
{
    if ($arquivo === '') {
        $arquivo = $pasta . '/' . date('Y-m-d\TH-i-s') . '-' . bin2hex(random_bytes(6)) . '.json';
    }
    $ok = @file_put_contents($arquivo, json_encode($registro, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($ok === false) {
        error_log("Não foi possível gravar o backup em $arquivo");
        return '';
    }
    return $arquivo;
}

/**
 * Envia os dados ao ERP. Devolve true só se a resposta for 2xx.
 */
function hm_enviar_erp(array $config, string $funcao, array $payload, string $rotulo): bool
{
    if (empty($config['token'])) {
        error_log("ERRO $rotulo: chave \"token\" do ERP ausente na configuração; envio ao ERP ignorado");
        return false;
    }
    $url = rtrim($config['erpUrl'] ?? HM_ERP_BASE_URL, '/') . '/' . $funcao;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['token'],
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $resposta = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    $ok = $resposta !== false && $status >= 200 && $status < 300;
    error_log(sprintf(
        '%s %s: status=%d resposta=%s',
        $ok ? 'INFO' : 'ERRO',
        $rotulo,
        $status,
        $resposta !== false ? mb_substr((string) $resposta, 0, 500) : "falha de conexão ($erroCurl)"
    ));
    return $ok;
}

/**
 * Envia o e-mail de aviso pelo Gmail (OAuth2). $campos = ['Rótulo' => 'valor'].
 * Os valores são escapados; a mensagem longa vai em $mensagem (mantém quebras de linha).
 */
function hm_enviar_email(array $config, string $remetente, string $assunto, string $titulo, array $campos, string $mensagem, string $responderEmail, string $responderNome): bool
{
    foreach (['gmailUser', 'clientId', 'clientSecret', 'refreshToken', 'to'] as $chave) {
        if (empty($config[$chave])) {
            error_log("ERRO e-mail: chave \"$chave\" ausente na configuração; e-mail não enviado");
            return false;
        }
    }

    $h = function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $corpo = '<h3>' . $h($titulo) . '</h3>';
    foreach ($campos as $rotulo => $valor) {
        $corpo .= '<p><b>' . $h($rotulo) . ':</b> ' . $h((string) $valor) . '</p>';
    }
    if ($mensagem !== '') {
        $corpo .= '<p><b>Mensagem:</b><br>' . nl2br($h($mensagem)) . '</p>';
    }

    $texto = $titulo . "\n\n";
    foreach ($campos as $rotulo => $valor) {
        $texto .= "$rotulo: $valor\n";
    }
    if ($mensagem !== '') {
        $texto .= "\nMensagem:\n$mensagem\n";
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->Port       = 587;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth   = true;
        $mail->AuthType   = 'XOAUTH2';
        $mail->Timeout    = 15;

        $provider = new Google([
            'clientId'     => $config['clientId'],
            'clientSecret' => $config['clientSecret'],
        ]);
        $mail->setOAuth(new OAuth([
            'provider'     => $provider,
            'clientId'     => $config['clientId'],
            'clientSecret' => $config['clientSecret'],
            'refreshToken' => $config['refreshToken'],
            'userName'     => $config['gmailUser'],
        ]));

        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->setFrom($config['gmailUser'], $remetente);
        foreach ((array) $config['to'] as $destino) {
            $mail->addAddress($destino);
        }
        if ($responderEmail !== '') {
            $mail->addReplyTo($responderEmail, $responderNome);
        }

        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body    = $corpo;
        $mail->AltBody = $texto;
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('ERRO ao enviar e-mail: ' . ($mail->ErrorInfo ?: $e->getMessage()));
        return false;
    }
}

/**
 * Converte o tipo da mensagem para o formato do ERP: minúsculo e sem acentos ("Reclamação" -> "reclamacao").
 */
function hm_sem_acentos_minusculo(string $texto): string
{
    $texto = mb_strtolower($texto, 'UTF-8');
    return strtr($texto, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ]);
}
