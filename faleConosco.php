<?php
// ------------------------------------------------------------
// FORMULÁRIO "FALE CONOSCO" (contato da Área do Assinante)
// Recebe: name, email, phone, type, message e, opcionalmente, crm
// Envia ao ERP (contact-us) e por e-mail; guarda backup em /dados
// ------------------------------------------------------------
require_once __DIR__ . '/inc/formulario.php';

date_default_timezone_set('America/Sao_Paulo');

$mensagemErro    = 'Não foi possível registrar sua mensagem. Tente novamente ou fale com a gente pelo WhatsApp.';
$mensagemSucesso = 'Recebemos sua mensagem';

$config = hm_carregar_config(__DIR__ . '/conf/faleConoscoConfig.json');
hm_cors($config);

// ------------------------------------------------------------
// LEITURA E VALIDAÇÃO
// ------------------------------------------------------------
$entrada = hm_ler_dados();

if (hm_eh_robo($entrada)) {
    error_log('faleConosco: envio descartado pelo campo-armadilha (robô)');
    hm_responder(200, true, $mensagemSucesso);
}

[$dados, $erro] = hm_validar($entrada, [
    //  campo       rótulo                 obrigatório  máx.  multilinha
    'name'    => ['Nome completo',         true,        120,  false],
    'crm'     => ['CRM',                   false,       20,   false],
    'email'   => ['E-mail',                true,        160,  false],
    'phone'   => ['Telefone (WhatsApp)',   true,        30,   false],
    'type'    => ['Tipo',                  true,        40,   false],
    'message' => ['Mensagem',              true,        5000, true],
]);
if ($erro !== null) {
    hm_responder(400, false, $erro);
}

$ip    = hm_ip_cliente();
$pasta = hm_pasta_dados($config);

if (hm_excedeu_limite($pasta, 'faleConosco', $ip, (int) ($config['rateLimitMax'] ?? 5), (int) ($config['rateLimitWindow'] ?? 600))) {
    error_log("faleConosco: limite de envios excedido para o IP $ip");
    hm_responder(429, false, 'Recebemos muitos envios em pouco tempo. Aguarde alguns minutos e tente de novo.');
}

// ------------------------------------------------------------
// BACKUP LOCAL (antes de qualquer envio)
// ------------------------------------------------------------
$registro = [
    'formulario' => 'faleConosco',
    'recebidoEm' => date('c'),
    'ip'         => $ip,
    'dados'      => $dados,
];
$arquivoBackup = hm_salvar_backup($pasta, $registro);

// ------------------------------------------------------------
// 1️⃣ ENVIO AO ERP
// ------------------------------------------------------------
$payloadErp = [
    'full_name'    => $dados['name'],
    'email'        => $dados['email'],
    'phone'        => $dados['phone'],
    'message_type' => hm_sem_acentos_minusculo($dados['type']),
    'message'      => $dados['message'],
    'client_ip'    => $ip,
];
// O CRM só vai ao ERP quando a configuração informa o nome do campo (ex.: "erpCrmField": "crm").
if (!empty($config['erpCrmField']) && $dados['crm'] !== '') {
    $payloadErp[$config['erpCrmField']] = $dados['crm'];
}
$erpOk = hm_enviar_erp($config, 'contact-us', $payloadErp, 'enviarFaleConosco');

// ------------------------------------------------------------
// 2️⃣ E-MAIL DE AVISO
// ------------------------------------------------------------
$campos = ['Nome' => $dados['name']];
if ($dados['crm'] !== '') {
    $campos['CRM'] = $dados['crm'];
}
$campos += [
    'E-mail'   => $dados['email'],
    'Telefone' => $dados['phone'],
    'Tipo'     => $dados['type'],
    'Registrado no ERP' => $erpOk ? 'sim' : 'NÃO — cadastrar manualmente',
];

$emailOk = hm_enviar_email(
    $config,
    'HelpMédico - Formulário Fale Conosco',
    'Fale Conosco: ' . $dados['type'] . ' de ' . $dados['name'],
    'Nova mensagem recebida',
    $campos,
    $dados['message'],
    $dados['email'],
    $dados['name']
);

// ------------------------------------------------------------
// RESPOSTA FINAL
// ------------------------------------------------------------
if ($arquivoBackup !== '') {
    $registro['erp']   = $erpOk;
    $registro['email'] = $emailOk;
    hm_salvar_backup($pasta, $registro, $arquivoBackup);
}

if (!$erpOk && !$emailOk) {
    error_log('ERRO faleConosco: ERP e e-mail falharam' . ($arquivoBackup !== '' ? "; dados salvos em $arquivoBackup" : '; backup também falhou'));
    hm_responder(500, false, $mensagemErro);
}

hm_responder(200, true, $mensagemSucesso);
