<?php
// ------------------------------------------------------------
// CABEÇALHO E CONFIGURAÇÃO INICIAL
// ------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

$mensagemErro       = 'Não foi possível registrar sua mensagem';
$mensagemSucesso    = 'Recebemos sua mensagem';

// Caminho onde os dados serão salvos
$dataDir = __DIR__ . '/dados';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// ------------------------------------------------------------
// LEITURA DOS DADOS ENVIADOS
// ------------------------------------------------------------
$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data) $data = $_POST;

// Validação básica
$requiredFields = ['email', 'message', 'name', 'phone', 'type'];
foreach ($requiredFields as $field) {
    if (empty($data[$field])) {
        error_log("Campo obrigatório ausente: $field");

        http_response_code(400);
        echo json_encode(['resposta' => "Campo obrigatório ausente: $field", 'sucesso' => false]);

        exit;
    }
}

// ------------------------------------------------------------
// SALVA LOCALMENTE (LOG DE BACKUP)
// ------------------------------------------------------------
$filename = $dataDir . '/' . date('Y-m-d\TH-i-s') . '-' . uniqid() . '.json';
file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ------------------------------------------------------------
// 1️⃣ LÊ CONFIGURAÇÕES DO ARQUIVO config.json
// ------------------------------------------------------------
$configFile = __DIR__ . '/conf/faleConoscoConfig.json';
if (!file_exists($configFile)) {
    error_log("Arquivo de configuração não encontrado: $configFile");

    http_response_code(500);
    echo json_encode(['resposta' => $mensagemErro, 'sucesso' => false]);

    exit;
}

$configData = json_decode(file_get_contents($configFile), true);
if (
    !$configData ||
    empty($configData['gmailUser']) ||
    empty($configData['clientId']) ||
    empty($configData['clientSecret']) ||
    empty($configData['refreshToken']) ||
    empty($configData['to'])
) {
    error_log("Arquivo de configuração inválido ou incompleto: $configFile");

    http_response_code(500);
    echo json_encode(['resposta' => $mensagemErro, 'sucesso' => false]);
    exit;
}

$gmailUser    = $configData['gmailUser'];
$clientId     = $configData['clientId'];
$clientSecret = $configData['clientSecret'];
$refreshToken = $configData['refreshToken'];
$to           = $configData['to'];

// ------------------------------------------------------------
// 2️⃣ ENVIO DE EMAIL VIA GMAIL API (OAuth2)
// ------------------------------------------------------------
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\OAuth;
use League\OAuth2\Client\Provider\Google;

require __DIR__ . '/vendor/autoload.php';

// Montagem do e-mail
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->Port       = 587;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPAuth   = true;
    $mail->AuthType   = 'XOAUTH2';

    // Configura OAuth2
    $provider = new Google([
        'clientId'     => $clientId,
        'clientSecret' => $clientSecret,
    ]);

    $mail->setOAuth(new OAuth([
        'provider'       => $provider,
        'clientId'       => $clientId,
        'clientSecret'   => $clientSecret,
        'refreshToken'   => $refreshToken,
        'userName'       => $gmailUser,
    ]));

    // Cabeçalhos e destinatários
    $mail->setFrom($gmailUser, 'HelpMédico - Formulário faleConosco.php');
    $mail->addAddress($to, $to);
    $mail->addReplyTo($data['email'], $data['name']);

    // 🔤 Define codificação correta para acentos
    $mail->CharSet  = 'UTF-8';
    $mail->Encoding = 'base64';

    // Corpo do e-mail
    $mail->isHTML(true);
    $mail->Subject = "Fale Conosco: Nova Mensagem de {$data['type']}";
    $mail->Body    = "
        <h3>Nova mensagem recebida</h3>
        <p><b>Nome:</b> {$data['name']}</p>
        <p><b>Email:</b> {$data['email']}</p>
        <p><b>Telefone:</b> {$data['phone']}</p>
        <p><b>Tipo:</b> {$data['type']}</p>
        <p><b>Mensagem:</b><br>{$data['message']}</p>
    ";
    $mail->AltBody = strip_tags($mail->Body);

    $mail->send();
    $emailStatus = true;

    trigger_error("Dados do Formulário enviado para $to", E_USER_WARNING);
} catch (Exception $e) {
    $emailStatus = false;
    error_log('Erro ao enviar e-mail: ' . $mail->ErrorInfo);
}

// ------------------------------------------------------------
// RESPOSTA FINAL
// ------------------------------------------------------------
echo json_encode([
    'resposta' => $mensagemSucesso,
    'sucesso' => true
]);
