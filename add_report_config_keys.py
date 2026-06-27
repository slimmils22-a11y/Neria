import json

with open('data/admin_translations.json', encoding='utf-8') as f:
    data = json.load(f)

new_keys = {
  'configure.report_title': {
    'fr':'Rapport mensuel automatique','en':'Automatic monthly report','de':'Automatischer Monatsbericht',
    'it':'Report mensile automatico','es':'Informe mensual automatico','pt':'Relatorio mensal automatico',
    'br':'Relatorio mensal automatico','ar':'التقرير الشهري التلقائي','ja':'自動月次レポート',
    'ko':'자동 월간 보고서','zh':'自动月度报告','tw':'自動月度報告','ru':'Автоматический ежемесячный отчёт',
    'tr':'Otomatik aylik rapor','sv':'Automatisk manadsrapport','no':'Automatisk manedlig rapport',
    'da':'Automatisk manedlig rapport','nl':'Automatisch maandrapport'
  },
  'configure.report_desc': {
    'fr':'Envoyez automatiquement un rapport de performance email au debut de chaque mois : top/flop templates, taux d\'ouverture, CA attribue et recommandations.',
    'en':'Automatically send an email performance report at the start of each month: top/flop templates, open rates, attributed revenue and recommendations.',
    'de':'Senden Sie automatisch einen E-Mail-Leistungsbericht zu Monatsbeginn: Top/Flop-Templates, Oeffnungsraten, Umsatz und Empfehlungen.',
    'it':'Invia automaticamente un report di performance email a inizio mese: template top/flop, tassi di apertura, ricavi attribuiti e raccomandazioni.',
    'es':'Envia automaticamente un informe de rendimiento email a principios de mes: top/flop, tasas de apertura, ingresos atribuidos y recomendaciones.',
    'pt':'Envie automaticamente um relatorio de desempenho de email no inicio de cada mes: top/flop de templates, taxas de abertura, receita atribuida e recomendacoes.',
    'br':'Envie automaticamente um relatorio de desempenho de email no inicio de cada mes: top/flop de templates, taxas de abertura, receita atribuida e recomendacoes.',
    'ar':'ارسل تلقائيا تقرير اداء البريد في بداية كل شهر: افضل/اسوا القوالب، معدلات الفتح، الايرادات والتوصيات.',
    'ja':'毎月初めに自動でメールパフォーマンスレポートを送信：トップ/フロップテンプレート、開封率、帰属収益、推奨事項。',
    'ko':'매월 초 이메일 성과 보고서를 자동 발송: 상위/하위 템플릿, 열람률, 귀속 매출 및 권장사항.',
    'zh':'每月初自动发送邮件绩效报告：最佳/最差模板、打开率、归因收入和建议。',
    'tw':'每月初自動發送郵件績效報告：最佳/最差模板、開信率、歸因收入和建議。',
    'ru':'Автоматически отправляйте отчёт об эффективности email в начале каждого месяца.',
    'tr':'Her ay basinda otomatik olarak e-posta performans raporu gonderin.',
    'sv':'Skicka automatiskt en e-postprestandarapport i borjan av varje manad.',
    'no':'Send automatisk en e-postprestandarapport i begynnelsen av hver maned.',
    'da':'Send automatisk en e-mailpraestationsrapport i starten af hver maned.',
    'nl':'Stuur automatisch een e-mailprestatierapport aan het begin van elke maand.'
  },
  'configure.report_enabled_label': {
    'fr':'Activer le rapport mensuel','en':'Enable monthly report','de':'Monatsbericht aktivieren',
    'it':'Abilita report mensile','es':'Activar informe mensual','pt':'Ativar relatorio mensal',
    'br':'Ativar relatorio mensal','ar':'تفعيل التقرير الشهري','ja':'月次レポートを有効にする',
    'ko':'월간 보고서 활성화','zh':'启用月度报告','tw':'啟用月度報告','ru':'Включить ежемесячный отчёт',
    'tr':'Aylik raporu etkinlestir','sv':'Aktivera manadsrapport','no':'Aktiver manedlig rapport',
    'da':'Aktiver manedlig rapport','nl':'Maandrapport inschakelen'
  },
  'configure.report_recipients_label': {
    'fr':'Destinataire(s) du rapport','en':'Report recipient(s)','de':'Berichtsempfanger',
    'it':'Destinatario/i del report','es':'Destinatario(s) del informe','pt':'Destinatario(s) do relatorio',
    'br':'Destinatario(s) do relatorio','ar':'مستلم التقرير','ja':'レポート受信者',
    'ko':'보고서 수신자','zh':'报告收件人','tw':'報告收件人','ru':'Получатель отчёта',
    'tr':'Rapor alicisi','sv':'Rapportmottagare','no':'Rapportmottaker',
    'da':'Rapportmodtager','nl':'Rapportontvanger'
  },
  'configure.report_recipients_hint': {
    'fr':'Par defaut : email principal de la boutique. Plusieurs adresses separees par des virgules.',
    'en':'Default: shop main email. Multiple addresses separated by commas.',
    'de':'Standard: Haupt-E-Mail des Shops. Mehrere Adressen mit Komma trennen.',
    'it':'Predefinito: email principale del negozio. Piu indirizzi separati da virgole.',
    'es':'Por defecto: email principal de la tienda. Varias direcciones separadas por comas.',
    'pt':'Padrao: email principal da loja. Varios enderecos separados por virgulas.',
    'br':'Padrao: email principal da loja. Varios enderecos separados por virgulas.',
    'ar':'افتراضي: البريد الرئيسي للمتجر. عناوين متعددة مفصولة بفواصل.',
    'ja':'デフォルト: ショップのメインメール。複数のアドレスはカンマで区切り。',
    'ko':'기본값: 쇼핑몰 대표 이메일. 여러 주소는 쉼표로 구분.',
    'zh':'默认：商店主要邮箱。多个地址用逗号分隔。',
    'tw':'預設：商店主要郵箱。多個地址用逗號分隔。',
    'ru':'По умолчанию: основной email магазина. Несколько адресов через запятую.',
    'tr':'Varsayilan: magazanin ana e-postasi. Birden fazla adres virgülle ayrilir.',
    'sv':'Standard: butikens e-post. Flera adresser separerade med kommatecken.',
    'no':'Standard: butikkens e-post. Flere adresser atskilt med komma.',
    'da':'Standard: butikkens e-mail. Flere adresser adskilt med kommaer.',
    'nl':'Standaard: hoofd-e-mailadres van de winkel. Meerdere adressen door kommas gescheiden.'
  },
  'configure.report_last_sent': {
    'fr':'Dernier envoi :','en':'Last sent:','de':'Zuletzt gesendet:','it':'Ultimo invio:',
    'es':'Ultimo envio:','pt':'Ultimo envio:','br':'Ultimo envio:','ar':'اخر ارسال:',
    'ja':'最終送信:','ko':'마지막 발송:','zh':'上次发送:','tw':'上次發送:',
    'ru':'Последняя отправка:','tr':'Son gonderim:','sv':'Senast skickad:',
    'no':'Sist sendt:','da':'Sidst sendt:','nl':'Laatste verzending:'
  },
  'configure.report_send_now': {
    'fr':'Envoyer le rapport maintenant (mois precedent)','en':'Send report now (previous month)',
    'de':'Bericht jetzt senden (Vormonat)','it':'Invia il report ora (mese precedente)',
    'es':'Enviar informe ahora (mes anterior)','pt':'Enviar relatorio agora (mes anterior)',
    'br':'Enviar relatorio agora (mes anterior)','ar':'ارسال التقرير الان (الشهر الماضي)',
    'ja':'今すぐレポートを送信（先月分）','ko':'지금 보고서 발송 (전월)',
    'zh':'立即发送报告（上月）','tw':'立即發送報告（上月）',
    'ru':'Отправить отчёт сейчас (за прошлый месяц)','tr':'Raporu simdi gonder (onceki ay)',
    'sv':'Skicka rapport nu (foregaende manad)','no':'Send rapport na (forrige maned)',
    'da':'Send rapport nu (forrige maned)','nl':'Rapport nu verzenden (vorige maand)'
  }
}

data.update(new_keys)

with open('data/admin_translations.json', 'w', encoding='utf-8') as f:
    json.dump(data, f, ensure_ascii=False, indent=4)

print('OK -', len(data), 'cles au total')
