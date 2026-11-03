<?php
// ------------------------------------------------------------
// FORMULÁRIO "QUERO CONHECER" (seção "Pronto para simplificar sua vida?" do site)
// Recebe: name, crm, email, phone, referral
// Envia ao ERP (want-to-know) e por e-mail; guarda backup em /dados
// ------------------------------------------------------------
require_once __DIR__ . '/inc/formulario.php';

date_default_timezone_set('America/Sao_Paulo');

$mensagemErro    = 'Não foi possível registrar seu interesse. Tente novamente ou fale com a gente pelo WhatsApp.';
$mensagemSucesso = 'Recebemos sua solicitação';

$config = hm_carregar_config(__DIR__ . '/conf/queroConhecerConfig.json');
hm_cors($config);

// ------------------------------------------------------------
// LEITURA E VALIDAÇÃO
// ------------------------------------------------------------
$entrada = hm_ler_dados();

if (hm_eh_robo($entrada)) {
    error_log('queroConhecer: envio descartado pelo campo-armadilha (robô)');
    hm_responder(200, true, $mensagemSucesso);
}

[$dados, $erro] = hm_validar($entrada, [
    //  campo        rótulo                       obrigatório  máx.  multilinha
    'name'     => ['Nome completo',               true,        120,  false],
    'crm'      => ['CRM',                         true,        20,   false],
    'email'    => ['E-mail',                      true,        160,  false],
    'phone'    => ['Telefone (WhatsApp)',         true,        30,   false],
    'referral' => ['Quem indicou a Help Médico',  true,        120,  false],
]);
if ($erro !== null) {
    hm_responder(400, false, $erro);
}

$ip    = hm_ip_cliente();
$pasta = hm_pasta_dados($config);

if (hm_excedeu_limite($pasta, 'queroConhecer', $ip, (int) ($config['rateLimitMax'] ?? 5), (int) ($config['rateLimitWindow'] ?? 600))) {
    error_log("queroConhecer: limite de envios excedido para o IP $ip");
    hm_responder(429, false, 'Recebemos muitos envios em pouco tempo. Aguarde alguns minutos e tente de novo.');
}

// ------------------------------------------------------------
// BACKUP LOCAL (antes de qualquer envio)
// ------------------------------------------------------------
$registro = [
    'formulario' => 'queroConhecer',
    'recebidoEm' => date('c'),
    'ip'         => $ip,
    'dados'      => $dados,
];
$arquivoBackup = hm_salvar_backup($pasta, $registro);

// ------------------------------------------------------------
// 1️⃣ ENVIO AO ERP
// ------------------------------------------------------------
$payloadErp = [
    'full_name'   => $dados['name'],
    'email'       => $dados['email'],
    'phone'       => $dados['phone'],
    'referred_by' => $dados['referral'],
    'client_ip'   => $ip,
];
// O CRM só vai ao ERP quando a configuração informa o nome do campo (ex.: "erpCrmField": "crm").
if (!empty($config['erpCrmField'])) {
    $payloadErp[$config['erpCrmField']] = $dados['crm'];
}
$erpOk = hm_enviar_erp($config, 'want-to-know', $payloadErp, 'enviarQueroConhecer');

// ------------------------------------------------------------
// 2️⃣ E-MAIL DE AVISO
// ------------------------------------------------------------
$emailOk = hm_enviar_email(
    $config,
    'HelpMédico - Formulário Quero Conhecer',
    'Quero Conhecer: ' . $dados['name'],
    'Nova solicitação recebida',
    [
        'Nome'         => $dados['name'],
        'CRM'          => $dados['crm'],
        'E-mail'       => $dados['email'],
        'Telefone'     => $dados['phone'],
        'Indicado por' => $dados['referral'],
        'Registrado no ERP' => $erpOk ? 'sim' : 'NÃO — cadastrar manualmente',
    ],
    '',
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
    error_log('ERRO queroConhecer: ERP e e-mail falharam' . ($arquivoBackup !== '' ? "; dados salvos em $arquivoBackup" : '; backup também falhou'));
    hm_responder(500, false, $mensagemErro);
}

hm_responder(200, true, $mensagemSucesso);
