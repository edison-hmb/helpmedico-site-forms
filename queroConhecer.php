<?php
// ------------------------------------------------------------
// CABEÇALHO E CONFIGURAÇÃO INICIAL
// ------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

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
$requiredFields = ['name', 'email', 'phone', 'crm', 'specialty', 'location', 'message'];
foreach ($requiredFields as $field) {
    if (empty($data[$field])) {
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
// 1️⃣ ENVIO DE EMAIL VIA GMAIL API (OAuth2)
// ------------------------------------------------------------
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\OAuth;
use League\OAuth2\Client\Provider\Google;

require __DIR__ . '/vendor/autoload.php';

// Credenciais do Gmail API (OAuth2)
$gmailUser     = ''; // e-mail remetente (Workspace)
$clientId      = '';
$clientSecret  = '';
$refreshToken  = '';

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
    $mail->setFrom($gmailUser, 'Help Médico - Formulário de Interesse');
    $mail->addAddress('admin@helpmedico.com.br', 'Equipe Help Médico');
    $mail->addReplyTo($data['email'], $data['name']);

    // Corpo do e-mail
    $mail->isHTML(true);
    $mail->Subject = 'Novo formulário - Quero Conhecer a Help Médico';
    $mail->Body    = "
        <h3>Nova solicitação recebida</h3>
        <p><b>Nome:</b> {$data['name']}</p>
        <p><b>Email:</b> {$data['email']}</p>
        <p><b>Telefone:</b> {$data['phone']}</p>
        <p><b>CRM:</b> {$data['crm']}</p>
        <p><b>Especialidade:</b> {$data['specialty']}</p>
        <p><b>Localização:</b> {$data['location']}</p>
        <p><b>Mensagem:</b><br>{$data['message']}</p>
        <hr>
        <p>Arquivo salvo: {$filename}</p>
    ";
    $mail->AltBody = strip_tags($mail->Body);

    $mail->send();
    $emailStatus = true;
} catch (Exception $e) {
    $emailStatus = false;
    error_log('Erro ao enviar e-mail: ' . $mail->ErrorInfo);
}

/*
// ------------------------------------------------------------
// 2️⃣ ABERTURA AUTOMÁTICA DE CHAMADO NO GLPI
// ------------------------------------------------------------
$glpiApiUrl = 'https://glpi.helpmedico.com.br/apirest.php';
$glpiToken  = 'TOKEN_GLPI_AQUI';

try {
    // Inicia sessão GLPI
    $ch = curl_init("$glpiApiUrl/initSession");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: user_token $glpiToken",
            "Content-Type: application/json"
        ]
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($response['session_token'])) {
        $sessionToken = $response['session_token'];

        // Cria o chamado
        $ticketData = [
            'input' => [
                'name'        => "Contato de {$data['name']}",
                'content'     => "Nova solicitação via formulário Help Médico\n\n" .
                                 "E-mail: {$data['email']}\n" .
                                 "Telefone: {$data['phone']}\n" .
                                 "CRM: {$data['crm']}\n" .
                                 "Especialidade: {$data['specialty']}\n" .
                                 "Localização: {$data['location']}\n\n" .
                                 "Mensagem:\n{$data['message']}",
                'status'      => 1, // Novo
                'priority'    => 3, // Normal
                'type'        => 1  // Incidente
            ]
        ];

        $ch = curl_init("$glpiApiUrl/Ticket");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($ticketData),
            CURLOPT_HTTPHEADER => [
                "Session-Token: $sessionToken",
                "Content-Type: application/json"
            ]
        ]);
        $ticketResponse = json_decode(curl_exec($ch), true);
        curl_close($ch);

        // Fecha sessão
        $ch = curl_init("$glpiApiUrl/killSession");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Session-Token: $sessionToken"]
        ]);
        curl_exec($ch);
        curl_close($ch);

        $glpiStatus = isset($ticketResponse['id']);
    } else {
        $glpiStatus = false;
    }
} catch (Exception $e) {
    $glpiStatus = false;
    error_log('Erro GLPI: ' . $e->getMessage());
}
*/ 

// ------------------------------------------------------------
// RESPOSTA FINAL
// ------------------------------------------------------------
echo json_encode([
    'resposta' => 'Solicitação enviada com sucesso',
    'salvo_em' => basename($filename),
    'email_enviado' => $emailStatus,
//    'chamado_glpi' => $glpiStatus,
    'sucesso' => true
]);

