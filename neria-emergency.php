<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Page d'urgence Watchdog
 *
 * Accessible SANS PrestaShop, directement via URL + token secret.
 * Utile quand une erreur 500 empêche d'accéder au back-office.
 *
 * URL : https://votre-boutique.com/modules/neria/neria-emergency.php?token=VOTRE_TOKEN
 *
 * Le token est affiché dans Neria → Aide → section Diagnostic.
 */

// ── Sécurité de base ─────────────────────────────────────────────
define('NERIA_EMERGENCY_VERSION', '1.0');
$startTime = microtime(true);

// Désactiver l'affichage des erreurs PHP (sécurité)
@ini_set('display_errors', '0');
@error_reporting(0);

// Le token vit dans l'URL (?token=...) par nécessité — cette page doit
// rester accessible sans session PrestaShop, donc pas d'alternative POST
// bookmarkable. Ces deux en-têtes réduisent le résidu d'exposition réel :
// Cache-Control empêche un proxy/navigateur de conserver l'URL (donc le
// token) en cache ; Referrer-Policy empêche sa fuite via l'en-tête Referer
// si un lien externe venait à être ajouté un jour sur cette page.
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Referrer-Policy: no-referrer');

// ── Langue (page autonome, sans AdminTranslator/PrestaShop) ──────
// Résolue via ?lang=xx (même URL que ?token=xx), repli sur l'anglais.
// Traductions embarquées directement ici — cette page doit rester
// fonctionnelle même si la base de données de traduction est
// inaccessible ou corrompue (c'est tout l'objet de cette page).
$EMERGENCY_I18N = [
'fr' => ['title_suffix'=>"Journal d'urgence",'header_sub'=>'Accès direct DB · Sans PrestaShop · %s · Généré en %sms','badge_emergency'=>'MODE URGENCE','alert_warn_body'=>"Cette page est accessible sans PrestaShop. Elle est protégée par un token secret. Ne partagez pas l'URL complète. Pour révoquer l'accès, régénérez le token dans Neria → Aide → Diagnostic.",'consecutive_failures_alert'=>'%d échecs de rendu consécutifs détectés.','consecutive_failures_note'=>"Neria utilise l'email de secours pour chaque envoi. Consultez le journal ci-dessous pour identifier la cause.",'section_overview'=>"Vue d'ensemble",'kpi_consecutive_failures'=>'Échecs consécutifs','kpi_active_bounces'=>'Bounces actifs','section_health_checks'=>'Derniers contrôles de santé','last_diagnostic'=>'Dernier diagnostic : %s','section_log'=>'Journal des événements (100 derniers)','filter_all_levels'=>'Tous les niveaux','filter_placeholder'=>'Filtrer par message ou classe…','th_date'=>'Date','th_level'=>'Niveau','th_class'=>'Classe','th_template'=>'Template','th_message'=>'Message','no_logs'=>'Aucun log trouvé','refresh'=>'Rafraîchir','footer_line'=>'Connexion directe DB · PS non chargé ·','config_missing'=>'Configuration PrestaShop introuvable. Consultez les logs serveur pour le détail.','db_connection_failed'=>'Connexion à la base de données impossible. Consultez les logs serveur pour le détail.','token_missing'=>'Token manquant. Ajoutez <code>?token=VOTRE_TOKEN</code> à l\'URL.','token_missing_hint'=>'Le token est visible dans Neria → Onglet Aide → section Diagnostic.','token_read_error'=>'Erreur de lecture en base. Consultez les logs serveur pour le détail.','token_invalid'=>'Token invalide. Accès refusé.','token_invalid_hint'=>'Le token correct est affiché dans Neria → Onglet Aide.','access_denied_title'=>'Accès refusé'],
'en' => ['title_suffix'=>'Emergency log','header_sub'=>'Direct DB access · Without PrestaShop · %s · Generated in %sms','badge_emergency'=>'EMERGENCY MODE','alert_warn_body'=>'This page is accessible without PrestaShop. It is protected by a secret token. Do not share the full URL. To revoke access, regenerate the token in Neria → Help → Diagnostic.','consecutive_failures_alert'=>'%d consecutive rendering failures detected.','consecutive_failures_note'=>'Neria is using the fallback email for every send. Check the log below to identify the cause.','section_overview'=>'Overview','kpi_consecutive_failures'=>'Consecutive failures','kpi_active_bounces'=>'Active bounces','section_health_checks'=>'Latest health checks','last_diagnostic'=>'Last diagnostic: %s','section_log'=>'Event log (last 100)','filter_all_levels'=>'All levels','filter_placeholder'=>'Filter by message or class…','th_date'=>'Date','th_level'=>'Level','th_class'=>'Class','th_template'=>'Template','th_message'=>'Message','no_logs'=>'No log found','refresh'=>'Refresh','footer_line'=>'Direct DB connection · PS not loaded ·','config_missing'=>'PrestaShop configuration not found. Check the server logs for details.','db_connection_failed'=>'Unable to connect to the database. Check the server logs for details.','token_missing'=>'Missing token. Add <code>?token=YOUR_TOKEN</code> to the URL.','token_missing_hint'=>'The token is visible in Neria → Help tab → Diagnostic section.','token_read_error'=>'Database read error. Check the server logs for details.','token_invalid'=>'Invalid token. Access denied.','token_invalid_hint'=>'The correct token is displayed in Neria → Help tab.','access_denied_title'=>'Access denied'],
'gb' => ['title_suffix'=>'Emergency log','header_sub'=>'Direct DB access · Without PrestaShop · %s · Generated in %sms','badge_emergency'=>'EMERGENCY MODE','alert_warn_body'=>'This page is accessible without PrestaShop. It is protected by a secret token. Do not share the full URL. To revoke access, regenerate the token in Neria → Help → Diagnostic.','consecutive_failures_alert'=>'%d consecutive rendering failures detected.','consecutive_failures_note'=>'Neria is using the fallback email for every send. Check the log below to identify the cause.','section_overview'=>'Overview','kpi_consecutive_failures'=>'Consecutive failures','kpi_active_bounces'=>'Active bounces','section_health_checks'=>'Latest health checks','last_diagnostic'=>'Last diagnostic: %s','section_log'=>'Event log (last 100)','filter_all_levels'=>'All levels','filter_placeholder'=>'Filter by message or class…','th_date'=>'Date','th_level'=>'Level','th_class'=>'Class','th_template'=>'Template','th_message'=>'Message','no_logs'=>'No log found','refresh'=>'Refresh','footer_line'=>'Direct DB connection · PS not loaded ·','config_missing'=>'PrestaShop configuration not found. Check the server logs for details.','db_connection_failed'=>'Unable to connect to the database. Check the server logs for details.','token_missing'=>'Missing token. Add <code>?token=YOUR_TOKEN</code> to the URL.','token_missing_hint'=>'The token is visible in Neria → Help tab → Diagnostic section.','token_read_error'=>'Database read error. Check the server logs for details.','token_invalid'=>'Invalid token. Access denied.','token_invalid_hint'=>'The correct token is displayed in Neria → Help tab.','access_denied_title'=>'Access denied'],
'de' => ['title_suffix'=>'Notfallprotokoll','header_sub'=>'Direkter DB-Zugriff · Ohne PrestaShop · %s · Erstellt in %sms','badge_emergency'=>'NOTFALLMODUS','alert_warn_body'=>'Diese Seite ist ohne PrestaShop zugänglich. Sie ist durch ein geheimes Token geschützt. Teilen Sie die vollständige URL nicht. Um den Zugriff zu widerrufen, generieren Sie das Token in Neria → Hilfe → Diagnose neu.','consecutive_failures_alert'=>'%d aufeinanderfolgende Rendering-Fehler festgestellt.','consecutive_failures_note'=>'Neria verwendet für jeden Versand die Ersatz-E-Mail. Prüfen Sie das Protokoll unten, um die Ursache zu identifizieren.','section_overview'=>'Übersicht','kpi_consecutive_failures'=>'Aufeinanderfolgende Fehler','kpi_active_bounces'=>'Aktive Bounces','section_health_checks'=>'Letzte Gesundheitsprüfungen','last_diagnostic'=>'Letzte Diagnose: %s','section_log'=>'Ereignisprotokoll (letzte 100)','filter_all_levels'=>'Alle Stufen','filter_placeholder'=>'Nach Nachricht oder Klasse filtern…','th_date'=>'Datum','th_level'=>'Stufe','th_class'=>'Klasse','th_template'=>'Vorlage','th_message'=>'Nachricht','no_logs'=>'Kein Protokoll gefunden','refresh'=>'Aktualisieren','footer_line'=>'Direkte DB-Verbindung · PS nicht geladen ·','config_missing'=>'PrestaShop-Konfiguration nicht gefunden. Details siehe Server-Logs.','db_connection_failed'=>'Datenbankverbindung nicht möglich. Details siehe Server-Logs.','token_missing'=>'Token fehlt. Fügen Sie <code>?token=IHR_TOKEN</code> zur URL hinzu.','token_missing_hint'=>'Das Token ist sichtbar unter Neria → Tab Hilfe → Diagnose.','token_read_error'=>'Fehler beim Lesen der Datenbank. Details siehe Server-Logs.','token_invalid'=>'Ungültiges Token. Zugriff verweigert.','token_invalid_hint'=>'Das korrekte Token wird unter Neria → Tab Hilfe angezeigt.','access_denied_title'=>'Zugriff verweigert'],
'it' => ['title_suffix'=>"Registro d'emergenza",'header_sub'=>'Accesso diretto al DB · Senza PrestaShop · %s · Generato in %sms','badge_emergency'=>"MODALITÀ D'EMERGENZA",'alert_warn_body'=>"Questa pagina è accessibile senza PrestaShop. È protetta da un token segreto. Non condividere l'URL completo. Per revocare l'accesso, rigenera il token in Neria → Aiuto → Diagnostica.",'consecutive_failures_alert'=>'%d errori di rendering consecutivi rilevati.','consecutive_failures_note'=>"Neria utilizza l'email di riserva per ogni invio. Consulta il registro qui sotto per identificare la causa.",'section_overview'=>"Panoramica",'kpi_consecutive_failures'=>'Errori consecutivi','kpi_active_bounces'=>'Bounce attivi','section_health_checks'=>'Ultimi controlli di integrità','last_diagnostic'=>'Ultima diagnostica: %s','section_log'=>'Registro eventi (ultimi 100)','filter_all_levels'=>'Tutti i livelli','filter_placeholder'=>'Filtra per messaggio o classe…','th_date'=>'Data','th_level'=>'Livello','th_class'=>'Classe','th_template'=>'Template','th_message'=>'Messaggio','no_logs'=>'Nessun log trovato','refresh'=>'Aggiorna','footer_line'=>'Connessione diretta al DB · PS non caricato ·','config_missing'=>'Configurazione PrestaShop non trovata. Consulta i log del server per i dettagli.','db_connection_failed'=>'Impossibile connettersi al database. Consulta i log del server per i dettagli.','token_missing'=>'Token mancante. Aggiungi <code>?token=IL_TUO_TOKEN</code> all\'URL.','token_missing_hint'=>'Il token è visibile in Neria → Scheda Aiuto → sezione Diagnostica.','token_read_error'=>'Errore di lettura dal database. Consulta i log del server per i dettagli.','token_invalid'=>'Token non valido. Accesso negato.','token_invalid_hint'=>'Il token corretto è mostrato in Neria → Scheda Aiuto.','access_denied_title'=>'Accesso negato'],
'es' => ['title_suffix'=>'Registro de emergencia','header_sub'=>'Acceso directo a la BD · Sin PrestaShop · %s · Generado en %sms','badge_emergency'=>'MODO DE EMERGENCIA','alert_warn_body'=>'Esta página es accesible sin PrestaShop. Está protegida por un token secreto. No comparta la URL completa. Para revocar el acceso, regenere el token en Neria → Ayuda → Diagnóstico.','consecutive_failures_alert'=>'%d fallos de renderizado consecutivos detectados.','consecutive_failures_note'=>'Neria utiliza el correo de respaldo para cada envío. Consulte el registro a continuación para identificar la causa.','section_overview'=>'Resumen','kpi_consecutive_failures'=>'Fallos consecutivos','kpi_active_bounces'=>'Rebotes activos','section_health_checks'=>'Últimas comprobaciones de estado','last_diagnostic'=>'Último diagnóstico: %s','section_log'=>'Registro de eventos (últimos 100)','filter_all_levels'=>'Todos los niveles','filter_placeholder'=>'Filtrar por mensaje o clase…','th_date'=>'Fecha','th_level'=>'Nivel','th_class'=>'Clase','th_template'=>'Plantilla','th_message'=>'Mensaje','no_logs'=>'No se encontró ningún registro','refresh'=>'Actualizar','footer_line'=>'Conexión directa a la BD · PS no cargado ·','config_missing'=>'No se encontró la configuración de PrestaShop. Consulte los logs del servidor para más detalles.','db_connection_failed'=>'No se puede conectar a la base de datos. Consulte los logs del servidor para más detalles.','token_missing'=>'Falta el token. Añada <code>?token=SU_TOKEN</code> a la URL.','token_missing_hint'=>'El token es visible en Neria → Pestaña Ayuda → sección Diagnóstico.','token_read_error'=>'Error de lectura en la base de datos. Consulte los logs del servidor para más detalles.','token_invalid'=>'Token no válido. Acceso denegado.','token_invalid_hint'=>'El token correcto se muestra en Neria → Pestaña Ayuda.','access_denied_title'=>'Acceso denegado'],
'pt' => ['title_suffix'=>'Registo de emergência','header_sub'=>'Acesso direto à BD · Sem PrestaShop · %s · Gerado em %sms','badge_emergency'=>'MODO DE EMERGÊNCIA','alert_warn_body'=>'Esta página é acessível sem PrestaShop. Está protegida por um token secreto. Não partilhe o URL completo. Para revogar o acesso, regenere o token em Neria → Ajuda → Diagnóstico.','consecutive_failures_alert'=>'%d falhas de renderização consecutivas detetadas.','consecutive_failures_note'=>'O Neria utiliza o e-mail de emergência para cada envio. Consulte o registo abaixo para identificar a causa.','section_overview'=>'Vista geral','kpi_consecutive_failures'=>'Falhas consecutivas','kpi_active_bounces'=>'Devoluções ativas','section_health_checks'=>'Últimas verificações de saúde','last_diagnostic'=>'Último diagnóstico: %s','section_log'=>'Registo de eventos (últimos 100)','filter_all_levels'=>'Todos os níveis','filter_placeholder'=>'Filtrar por mensagem ou classe…','th_date'=>'Data','th_level'=>'Nível','th_class'=>'Classe','th_template'=>'Template','th_message'=>'Mensagem','no_logs'=>'Nenhum registo encontrado','refresh'=>'Atualizar','footer_line'=>'Ligação direta à BD · PS não carregado ·','config_missing'=>'Configuração do PrestaShop não encontrada. Consulte os logs do servidor para mais detalhes.','db_connection_failed'=>'Não é possível ligar à base de dados. Consulte os logs do servidor para mais detalhes.','token_missing'=>'Token em falta. Adicione <code>?token=O_SEU_TOKEN</code> ao URL.','token_missing_hint'=>'O token está visível em Neria → Separador Ajuda → secção Diagnóstico.','token_read_error'=>'Erro de leitura na base de dados. Consulte os logs do servidor para mais detalhes.','token_invalid'=>'Token inválido. Acesso negado.','token_invalid_hint'=>'O token correto é apresentado em Neria → Separador Ajuda.','access_denied_title'=>'Acesso negado'],
'br' => ['title_suffix'=>'Registro de emergência','header_sub'=>'Acesso direto ao BD · Sem PrestaShop · %s · Gerado em %sms','badge_emergency'=>'MODO DE EMERGÊNCIA','alert_warn_body'=>'Esta página é acessível sem PrestaShop. Está protegida por um token secreto. Não compartilhe a URL completa. Para revogar o acesso, gere novamente o token em Neria → Ajuda → Diagnóstico.','consecutive_failures_alert'=>'%d falhas de renderização consecutivas detectadas.','consecutive_failures_note'=>'O Neria usa o e-mail de emergência para cada envio. Consulte o registro abaixo para identificar a causa.','section_overview'=>'Visão geral','kpi_consecutive_failures'=>'Falhas consecutivas','kpi_active_bounces'=>'Rejeições ativas','section_health_checks'=>'Últimas verificações de integridade','last_diagnostic'=>'Último diagnóstico: %s','section_log'=>'Registro de eventos (últimos 100)','filter_all_levels'=>'Todos os níveis','filter_placeholder'=>'Filtrar por mensagem ou classe…','th_date'=>'Data','th_level'=>'Nível','th_class'=>'Classe','th_template'=>'Template','th_message'=>'Mensagem','no_logs'=>'Nenhum registro encontrado','refresh'=>'Atualizar','footer_line'=>'Conexão direta ao BD · PS não carregado ·','config_missing'=>'Configuração do PrestaShop não encontrada. Consulte os logs do servidor para mais detalhes.','db_connection_failed'=>'Não é possível conectar ao banco de dados. Consulte os logs do servidor para mais detalhes.','token_missing'=>'Token ausente. Adicione <code>?token=SEU_TOKEN</code> à URL.','token_missing_hint'=>'O token está visível em Neria → Aba Ajuda → seção Diagnóstico.','token_read_error'=>'Erro de leitura no banco de dados. Consulte os logs do servidor para mais detalhes.','token_invalid'=>'Token inválido. Acesso negado.','token_invalid_hint'=>'O token correto é exibido em Neria → Aba Ajuda.','access_denied_title'=>'Acesso negado'],
'ar' => ['title_suffix'=>'سجل الطوارئ','header_sub'=>'وصول مباشر لقاعدة البيانات · بدون PrestaShop · %s · تم الإنشاء خلال %sمللي ثانية','badge_emergency'=>'وضع الطوارئ','alert_warn_body'=>'يمكن الوصول إلى هذه الصفحة دون PrestaShop. وهي محمية برمز سري. لا تشارك الرابط الكامل. لإلغاء الوصول، أعد توليد الرمز في Neria ← المساعدة ← التشخيص.','consecutive_failures_alert'=>'تم اكتشاف %d فشل متتالٍ في العرض.','consecutive_failures_note'=>'يستخدم Neria البريد الاحتياطي لكل إرسال. راجع السجل أدناه لتحديد السبب.','section_overview'=>'نظرة عامة','kpi_consecutive_failures'=>'فشل متتالٍ','kpi_active_bounces'=>'الارتدادات النشطة','section_health_checks'=>'أحدث فحوصات السلامة','last_diagnostic'=>'آخر تشخيص: %s','section_log'=>'سجل الأحداث (آخر 100)','filter_all_levels'=>'جميع المستويات','filter_placeholder'=>'تصفية حسب الرسالة أو الفئة…','th_date'=>'التاريخ','th_level'=>'المستوى','th_class'=>'الفئة','th_template'=>'القالب','th_message'=>'الرسالة','no_logs'=>'لم يتم العثور على أي سجل','refresh'=>'تحديث','footer_line'=>'اتصال مباشر بقاعدة البيانات · لم يتم تحميل PS ·','config_missing'=>'إعدادات PrestaShop غير موجودة. راجع سجلات الخادم للتفاصيل.','db_connection_failed'=>'تعذّر الاتصال بقاعدة البيانات. راجع سجلات الخادم للتفاصيل.','token_missing'=>'الرمز مفقود. أضف <code>?token=رمزك</code> إلى الرابط.','token_missing_hint'=>'الرمز مرئي في Neria ← تبويب المساعدة ← قسم التشخيص.','token_read_error'=>'خطأ في القراءة من قاعدة البيانات. راجع سجلات الخادم للتفاصيل.','token_invalid'=>'رمز غير صالح. تم رفض الوصول.','token_invalid_hint'=>'الرمز الصحيح معروض في Neria ← تبويب المساعدة.','access_denied_title'=>'تم رفض الوصول'],
'ja' => ['title_suffix'=>'緊急ログ','header_sub'=>'DB直接アクセス · PrestaShopなし · %s · %sms で生成','badge_emergency'=>'緊急モード','alert_warn_body'=>'このページはPrestaShopなしでアクセス可能です。秘密のトークンで保護されています。完全なURLを共有しないでください。アクセスを取り消すには、Neria → ヘルプ → 診断でトークンを再生成してください。','consecutive_failures_alert'=>'%d件の連続したレンダリング失敗が検出されました。','consecutive_failures_note'=>'Neriaは送信のたびに代替メールを使用しています。原因を特定するには以下のログを確認してください。','section_overview'=>'概要','kpi_consecutive_failures'=>'連続失敗','kpi_active_bounces'=>'アクティブなバウンス','section_health_checks'=>'最新のヘルスチェック','last_diagnostic'=>'最終診断：%s','section_log'=>'イベントログ（最新100件）','filter_all_levels'=>'すべてのレベル','filter_placeholder'=>'メッセージまたはクラスで絞り込む…','th_date'=>'日時','th_level'=>'レベル','th_class'=>'クラス','th_template'=>'テンプレート','th_message'=>'メッセージ','no_logs'=>'ログが見つかりません','refresh'=>'更新','footer_line'=>'DB直接接続 · PS未読み込み ·','config_missing'=>'PrestaShopの設定が見つかりません。詳細はサーバーログを確認してください。','db_connection_failed'=>'データベースに接続できません。詳細はサーバーログを確認してください。','token_missing'=>'トークンがありません。URLに<code>?token=あなたのトークン</code>を追加してください。','token_missing_hint'=>'トークンはNeria → ヘルプタブ → 診断セクションで確認できます。','token_read_error'=>'データベースの読み取りエラーです。詳細はサーバーログを確認してください。','token_invalid'=>'無効なトークンです。アクセスが拒否されました。','token_invalid_hint'=>'正しいトークンはNeria → ヘルプタブに表示されています。','access_denied_title'=>'アクセス拒否'],
'ko' => ['title_suffix'=>'긴급 로그','header_sub'=>'DB 직접 접근 · PrestaShop 없이 · %s · %sms 만에 생성됨','badge_emergency'=>'긴급 모드','alert_warn_body'=>'이 페이지는 PrestaShop 없이 접근할 수 있습니다. 비밀 토큰으로 보호됩니다. 전체 URL을 공유하지 마세요. 접근 권한을 취소하려면 Neria → 도움말 → 진단에서 토큰을 재생성하세요.','consecutive_failures_alert'=>'%d건의 연속 렌더링 실패가 감지되었습니다.','consecutive_failures_note'=>'Neria는 발송할 때마다 대체 이메일을 사용하고 있습니다. 원인을 파악하려면 아래 로그를 확인하세요.','section_overview'=>'개요','kpi_consecutive_failures'=>'연속 실패','kpi_active_bounces'=>'활성 반송','section_health_checks'=>'최근 상태 점검','last_diagnostic'=>'마지막 진단: %s','section_log'=>'이벤트 로그(최근 100건)','filter_all_levels'=>'모든 수준','filter_placeholder'=>'메시지 또는 클래스로 필터링…','th_date'=>'날짜','th_level'=>'수준','th_class'=>'클래스','th_template'=>'템플릿','th_message'=>'메시지','no_logs'=>'로그를 찾을 수 없습니다','refresh'=>'새로고침','footer_line'=>'DB 직접 연결 · PS 로드되지 않음 ·','config_missing'=>'PrestaShop 설정을 찾을 수 없습니다. 자세한 내용은 서버 로그를 확인하세요.','db_connection_failed'=>'데이터베이스에 연결할 수 없습니다. 자세한 내용은 서버 로그를 확인하세요.','token_missing'=>'토큰이 없습니다. URL에 <code>?token=귀하의_토큰</code>을 추가하세요.','token_missing_hint'=>'토큰은 Neria → 도움말 탭 → 진단 섹션에서 확인할 수 있습니다.','token_read_error'=>'데이터베이스 읽기 오류입니다. 자세한 내용은 서버 로그를 확인하세요.','token_invalid'=>'유효하지 않은 토큰입니다. 접근이 거부되었습니다.','token_invalid_hint'=>'올바른 토큰은 Neria → 도움말 탭에 표시됩니다.','access_denied_title'=>'접근 거부됨'],
'zh' => ['title_suffix'=>'紧急日志','header_sub'=>'直接数据库访问 · 无需PrestaShop · %s · 生成耗时%sms','badge_emergency'=>'紧急模式','alert_warn_body'=>'此页面可在不使用PrestaShop的情况下访问。它受到一个密钥令牌的保护。请勿分享完整的URL。要撤销访问权限，请在Neria → 帮助 → 诊断中重新生成令牌。','consecutive_failures_alert'=>'检测到%d次连续渲染失败。','consecutive_failures_note'=>'Neria正在为每次发送使用备用邮件。请查看下方日志以确定原因。','section_overview'=>'概览','kpi_consecutive_failures'=>'连续失败','kpi_active_bounces'=>'活跃退信','section_health_checks'=>'最新健康检查','last_diagnostic'=>'最后诊断：%s','section_log'=>'事件日志（最近100条）','filter_all_levels'=>'所有级别','filter_placeholder'=>'按消息或类别筛选…','th_date'=>'日期','th_level'=>'级别','th_class'=>'类','th_template'=>'模板','th_message'=>'消息','no_logs'=>'未找到日志','refresh'=>'刷新','footer_line'=>'直接数据库连接 · PS未加载 ·','config_missing'=>'未找到PrestaShop配置。请查看服务器日志了解详情。','db_connection_failed'=>'无法连接到数据库。请查看服务器日志了解详情。','token_missing'=>'缺少令牌。请在URL中添加<code>?token=您的令牌</code>。','token_missing_hint'=>'令牌可在Neria → 帮助标签 → 诊断部分查看。','token_read_error'=>'数据库读取错误。请查看服务器日志了解详情。','token_invalid'=>'令牌无效。访问被拒绝。','token_invalid_hint'=>'正确的令牌显示在Neria → 帮助标签中。','access_denied_title'=>'访问被拒绝'],
'tw' => ['title_suffix'=>'緊急日誌','header_sub'=>'直接資料庫存取 · 無需PrestaShop · %s · 生成耗時%sms','badge_emergency'=>'緊急模式','alert_warn_body'=>'此頁面可在不使用PrestaShop的情況下存取。它受到一個密鑰權杖的保護。請勿分享完整的URL。要撤銷存取權限，請在Neria → 說明 → 診斷中重新產生權杖。','consecutive_failures_alert'=>'偵測到%d次連續渲染失敗。','consecutive_failures_note'=>'Neria正在為每次寄送使用備用郵件。請查看下方日誌以確定原因。','section_overview'=>'概覽','kpi_consecutive_failures'=>'連續失敗','kpi_active_bounces'=>'活躍退信','section_health_checks'=>'最新健康檢查','last_diagnostic'=>'最後診斷：%s','section_log'=>'事件日誌（最近100筆）','filter_all_levels'=>'所有級別','filter_placeholder'=>'依訊息或類別篩選…','th_date'=>'日期','th_level'=>'級別','th_class'=>'類別','th_template'=>'範本','th_message'=>'訊息','no_logs'=>'找不到日誌','refresh'=>'重新整理','footer_line'=>'直接資料庫連線 · PS未載入 ·','config_missing'=>'找不到PrestaShop設定。請查看伺服器日誌以了解詳情。','db_connection_failed'=>'無法連線到資料庫。請查看伺服器日誌以了解詳情。','token_missing'=>'缺少權杖。請在URL中新增<code>?token=您的權杖</code>。','token_missing_hint'=>'權杖可在Neria → 說明標籤 → 診斷部分查看。','token_read_error'=>'資料庫讀取錯誤。請查看伺服器日誌以了解詳情。','token_invalid'=>'權杖無效。存取被拒絕。','token_invalid_hint'=>'正確的權杖顯示在Neria → 說明標籤中。','access_denied_title'=>'存取被拒絕'],
'ru' => ['title_suffix'=>'Журнал экстренной помощи','header_sub'=>'Прямой доступ к БД · Без PrestaShop · %s · Сформировано за %sмс','badge_emergency'=>'ЭКСТРЕННЫЙ РЕЖИМ','alert_warn_body'=>'Эта страница доступна без PrestaShop. Она защищена секретным токеном. Не делитесь полной ссылкой. Чтобы отозвать доступ, перегенерируйте токен в Neria → Справка → Диагностика.','consecutive_failures_alert'=>'Обнаружено %d последовательных сбоев рендеринга.','consecutive_failures_note'=>'Neria использует резервное письмо при каждой отправке. Проверьте журнал ниже, чтобы определить причину.','section_overview'=>'Обзор','kpi_consecutive_failures'=>'Последовательные сбои','kpi_active_bounces'=>'Активные отказы','section_health_checks'=>'Последние проверки состояния','last_diagnostic'=>'Последняя диагностика: %s','section_log'=>'Журнал событий (последние 100)','filter_all_levels'=>'Все уровни','filter_placeholder'=>'Фильтр по сообщению или классу…','th_date'=>'Дата','th_level'=>'Уровень','th_class'=>'Класс','th_template'=>'Шаблон','th_message'=>'Сообщение','no_logs'=>'Журнал не найден','refresh'=>'Обновить','footer_line'=>'Прямое подключение к БД · PS не загружен ·','config_missing'=>'Конфигурация PrestaShop не найдена. Подробности см. в логах сервера.','db_connection_failed'=>'Не удалось подключиться к базе данных. Подробности см. в логах сервера.','token_missing'=>'Токен отсутствует. Добавьте <code>?token=ВАШ_ТОКЕН</code> к URL.','token_missing_hint'=>'Токен виден в Neria → вкладка Справка → раздел Диагностика.','token_read_error'=>'Ошибка чтения базы данных. Подробности см. в логах сервера.','token_invalid'=>'Недействительный токен. Доступ запрещён.','token_invalid_hint'=>'Правильный токен отображается в Neria → вкладка Справка.','access_denied_title'=>'Доступ запрещён'],
'tr' => ['title_suffix'=>'Acil durum günlüğü','header_sub'=>'Doğrudan DB erişimi · PrestaShop olmadan · %s · %sms\'de oluşturuldu','badge_emergency'=>'ACİL DURUM MODU','alert_warn_body'=>'Bu sayfaya PrestaShop olmadan erişilebilir. Gizli bir jetonla korunmaktadır. Tam URL\'yi paylaşmayın. Erişimi iptal etmek için Neria → Yardım → Tanılama bölümünde jetonu yeniden oluşturun.','consecutive_failures_alert'=>'%d ardışık render hatası tespit edildi.','consecutive_failures_note'=>'Neria her gönderim için yedek e-postayı kullanıyor. Nedeni belirlemek için aşağıdaki günlüğe bakın.','section_overview'=>'Genel bakış','kpi_consecutive_failures'=>'Ardışık hatalar','kpi_active_bounces'=>'Aktif geri dönüşler','section_health_checks'=>'Son sağlık kontrolleri','last_diagnostic'=>'Son tanılama: %s','section_log'=>'Olay günlüğü (son 100)','filter_all_levels'=>'Tüm seviyeler','filter_placeholder'=>'Mesaj veya sınıfa göre filtrele…','th_date'=>'Tarih','th_level'=>'Seviye','th_class'=>'Sınıf','th_template'=>'Şablon','th_message'=>'Mesaj','no_logs'=>'Günlük bulunamadı','refresh'=>'Yenile','footer_line'=>'Doğrudan DB bağlantısı · PS yüklenmedi ·','config_missing'=>'PrestaShop yapılandırması bulunamadı. Ayrıntılar için sunucu günlüklerine bakın.','db_connection_failed'=>'Veritabanına bağlanılamıyor. Ayrıntılar için sunucu günlüklerine bakın.','token_missing'=>'Jeton eksik. URL\'ye <code>?token=JETONUNUZ</code> ekleyin.','token_missing_hint'=>'Jeton, Neria → Yardım sekmesi → Tanılama bölümünde görünür.','token_read_error'=>'Veritabanı okuma hatası. Ayrıntılar için sunucu günlüklerine bakın.','token_invalid'=>'Geçersiz jeton. Erişim reddedildi.','token_invalid_hint'=>'Doğru jeton Neria → Yardım sekmesinde görüntülenir.','access_denied_title'=>'Erişim reddedildi'],
'sv' => ['title_suffix'=>'Nödlogg','header_sub'=>'Direkt DB-åtkomst · Utan PrestaShop · %s · Genererad på %sms','badge_emergency'=>'NÖDLÄGE','alert_warn_body'=>'Denna sida är åtkomlig utan PrestaShop. Den skyddas av en hemlig token. Dela inte hela URL:en. För att återkalla åtkomst, generera om token i Neria → Hjälp → Diagnostik.','consecutive_failures_alert'=>'%d upprepade renderingsfel upptäckta.','consecutive_failures_note'=>'Neria använder reserv-e-post för varje utskick. Kontrollera loggen nedan för att identifiera orsaken.','section_overview'=>'Översikt','kpi_consecutive_failures'=>'Upprepade fel','kpi_active_bounces'=>'Aktiva studsar','section_health_checks'=>'Senaste hälsokontroller','last_diagnostic'=>'Senaste diagnos: %s','section_log'=>'Händelselogg (senaste 100)','filter_all_levels'=>'Alla nivåer','filter_placeholder'=>'Filtrera efter meddelande eller klass…','th_date'=>'Datum','th_level'=>'Nivå','th_class'=>'Klass','th_template'=>'Mall','th_message'=>'Meddelande','no_logs'=>'Ingen logg hittades','refresh'=>'Uppdatera','footer_line'=>'Direkt DB-anslutning · PS ej inläst ·','config_missing'=>'PrestaShop-konfiguration hittades inte. Se serverloggarna för detaljer.','db_connection_failed'=>'Det gick inte att ansluta till databasen. Se serverloggarna för detaljer.','token_missing'=>'Token saknas. Lägg till <code>?token=DIN_TOKEN</code> till URL:en.','token_missing_hint'=>'Token är synlig i Neria → fliken Hjälp → avsnittet Diagnostik.','token_read_error'=>'Databasläsfel. Se serverloggarna för detaljer.','token_invalid'=>'Ogiltig token. Åtkomst nekad.','token_invalid_hint'=>'Rätt token visas i Neria → fliken Hjälp.','access_denied_title'=>'Åtkomst nekad'],
'no' => ['title_suffix'=>'Nødlogg','header_sub'=>'Direkte DB-tilgang · Uten PrestaShop · %s · Generert på %sms','badge_emergency'=>'NØDMODUS','alert_warn_body'=>'Denne siden er tilgjengelig uten PrestaShop. Den er beskyttet av et hemmelig token. Ikke del hele URL-en. For å tilbakekalle tilgang, regenerer tokenet i Neria → Hjelp → Diagnostikk.','consecutive_failures_alert'=>'%d påfølgende gjengivelsesfeil oppdaget.','consecutive_failures_note'=>'Neria bruker reserve-e-post for hver utsendelse. Sjekk loggen nedenfor for å identifisere årsaken.','section_overview'=>'Oversikt','kpi_consecutive_failures'=>'Påfølgende feil','kpi_active_bounces'=>'Aktive avvisninger','section_health_checks'=>'Siste helsesjekker','last_diagnostic'=>'Siste diagnose: %s','section_log'=>'Hendelseslogg (siste 100)','filter_all_levels'=>'Alle nivåer','filter_placeholder'=>'Filtrer etter melding eller klasse…','th_date'=>'Dato','th_level'=>'Nivå','th_class'=>'Klasse','th_template'=>'Mal','th_message'=>'Melding','no_logs'=>'Ingen logg funnet','refresh'=>'Oppdater','footer_line'=>'Direkte DB-tilkobling · PS ikke lastet ·','config_missing'=>'PrestaShop-konfigurasjon ikke funnet. Se serverloggene for detaljer.','db_connection_failed'=>'Kan ikke koble til databasen. Se serverloggene for detaljer.','token_missing'=>'Token mangler. Legg til <code>?token=DITT_TOKEN</code> i URL-en.','token_missing_hint'=>'Tokenet er synlig i Neria → fanen Hjelp → seksjonen Diagnostikk.','token_read_error'=>'Databaselesefeil. Se serverloggene for detaljer.','token_invalid'=>'Ugyldig token. Tilgang nektet.','token_invalid_hint'=>'Riktig token vises i Neria → fanen Hjelp.','access_denied_title'=>'Tilgang nektet'],
'da' => ['title_suffix'=>'Nødlog','header_sub'=>'Direkte DB-adgang · Uden PrestaShop · %s · Genereret på %sms','badge_emergency'=>'NØDTILSTAND','alert_warn_body'=>'Denne side er tilgængelig uden PrestaShop. Den er beskyttet af en hemmelig token. Del ikke hele URL\'en. For at tilbagekalde adgang, gener token igen i Neria → Hjælp → Diagnosticering.','consecutive_failures_alert'=>'%d på hinanden følgende renderingsfejl registreret.','consecutive_failures_note'=>'Neria bruger nød-e-mail for hver afsendelse. Tjek loggen nedenfor for at identificere årsagen.','section_overview'=>'Oversigt','kpi_consecutive_failures'=>'På hinanden følgende fejl','kpi_active_bounces'=>'Aktive afvisninger','section_health_checks'=>'Seneste sundhedstjek','last_diagnostic'=>'Seneste diagnose: %s','section_log'=>'Hændelseslog (seneste 100)','filter_all_levels'=>'Alle niveauer','filter_placeholder'=>'Filtrer efter besked eller klasse…','th_date'=>'Dato','th_level'=>'Niveau','th_class'=>'Klasse','th_template'=>'Skabelon','th_message'=>'Besked','no_logs'=>'Ingen log fundet','refresh'=>'Opdater','footer_line'=>'Direkte DB-forbindelse · PS ikke indlæst ·','config_missing'=>'PrestaShop-konfiguration blev ikke fundet. Se serverloggene for detaljer.','db_connection_failed'=>'Kan ikke oprette forbindelse til databasen. Se serverloggene for detaljer.','token_missing'=>'Token mangler. Tilføj <code>?token=DIN_TOKEN</code> til URL\'en.','token_missing_hint'=>'Tokenet er synligt i Neria → fanen Hjælp → sektionen Diagnosticering.','token_read_error'=>'Databaselæsningsfejl. Se serverloggene for detaljer.','token_invalid'=>'Ugyldig token. Adgang nægtet.','token_invalid_hint'=>'Det korrekte token vises i Neria → fanen Hjælp.','access_denied_title'=>'Adgang nægtet'],
'nl' => ['title_suffix'=>'Noodlogboek','header_sub'=>'Directe DB-toegang · Zonder PrestaShop · %s · Gegenereerd in %sms','badge_emergency'=>'NOODMODUS','alert_warn_body'=>'Deze pagina is toegankelijk zonder PrestaShop. Ze wordt beschermd door een geheime token. Deel de volledige URL niet. Om toegang in te trekken, genereer de token opnieuw in Neria → Help → Diagnose.','consecutive_failures_alert'=>'%d opeenvolgende renderfouten gedetecteerd.','consecutive_failures_note'=>'Neria gebruikt de noodmail voor elke verzending. Controleer het logboek hieronder om de oorzaak te achterhalen.','section_overview'=>'Overzicht','kpi_consecutive_failures'=>'Opeenvolgende fouten','kpi_active_bounces'=>'Actieve bounces','section_health_checks'=>'Laatste gezondheidscontroles','last_diagnostic'=>'Laatste diagnose: %s','section_log'=>'Gebeurtenislogboek (laatste 100)','filter_all_levels'=>'Alle niveaus','filter_placeholder'=>'Filteren op bericht of klasse…','th_date'=>'Datum','th_level'=>'Niveau','th_class'=>'Klasse','th_template'=>'Template','th_message'=>'Bericht','no_logs'=>'Geen logboek gevonden','refresh'=>'Vernieuwen','footer_line'=>'Directe DB-verbinding · PS niet geladen ·','config_missing'=>'PrestaShop-configuratie niet gevonden. Zie de serverlogs voor details.','db_connection_failed'=>'Kan geen verbinding maken met de database. Zie de serverlogs voor details.','token_missing'=>'Token ontbreekt. Voeg <code>?token=UW_TOKEN</code> toe aan de URL.','token_missing_hint'=>'De token is zichtbaar in Neria → tabblad Help → sectie Diagnose.','token_read_error'=>'Fout bij het lezen van de database. Zie de serverlogs voor details.','token_invalid'=>'Ongeldige token. Toegang geweigerd.','token_invalid_hint'=>'De juiste token wordt weergegeven in Neria → tabblad Help.','access_denied_title'=>'Toegang geweigerd'],
];

$SUPPORTED_LANGS = array_keys($EMERGENCY_I18N);
$RTL_LANGS = ['ar'];
$emergencyLang = strtolower((string) ($_GET['lang'] ?? ''));
if (!in_array($emergencyLang, $SUPPORTED_LANGS, true)) {
    $emergencyLang = 'en';
}
$T = $EMERGENCY_I18N[$emergencyLang];
$emergencyDir = in_array($emergencyLang, $RTL_LANGS, true) ? 'rtl' : 'ltr';

/**
 * Traduit une clé de $T avec sprintf() si des arguments sont fournis.
 */
function e18n(array $T, string $key, ...$args): string
{
    $str = $T[$key] ?? $key;
    return $args ? vsprintf($str, $args) : $str;
}

// ── Connexion DB via parameters.php de PS ────────────────────────
$psRoot = realpath(__DIR__ . '/../../');
$paramsFile = $psRoot . '/app/config/parameters.php';

// Avant validation du token : aucun détail technique (chemin serveur, message
// d'exception PDO) n'est renvoyé à l'appelant — cette page est accessible sans
// authentification PrestaShop, un visiteur non autorisé ne doit rien apprendre
// sur l'infrastructure. Les détails complets vont uniquement au log PHP serveur.
if (!file_exists($paramsFile)) {
    error_log('[Neria emergency] Fichier de configuration introuvable : ' . $paramsFile);
    emergencyDie(e18n($T, 'config_missing'), $T, $emergencyDir, $emergencyLang);
}

// Round 166 : ce require n'était entouré d'aucun try/catch, et le bloc PDO
// ci-dessous ne rattrapait que \Exception, pas \Throwable. Un
// parameters.php corrompu syntaxiquement (déploiement interrompu — le
// scénario type de "PS core cassé" que cette page prétend justement
// survivre) lève un \ParseError/\Error, qui n'hérite PAS d'\Exception :
// ni rattrapé ici ni par le catch(Exception) plus bas, la page produisait
// une erreur fatale brute au lieu du message propre 'config_missing'/
// 'db_connection_failed' prévu par le design.
try {
    $params = require $paramsFile;
} catch (\Throwable $e) {
    error_log('[Neria emergency] Fichier de configuration illisible : ' . $e->getMessage());
    emergencyDie(e18n($T, 'config_missing'), $T, $emergencyDir, $emergencyLang);
}
$p      = $params['parameters'] ?? [];

$dbHost   = $p['database_host']     ?? 'localhost';
$dbPort   = $p['database_port']     ?: '3306';
$dbName   = $p['database_name']     ?? '';
$dbUser   = $p['database_user']     ?? '';
$dbPass   = $p['database_password'] ?? '';
$prefix   = $p['database_prefix']   ?? 'ps_';

try {
    $dsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort
         . ';dbname=' . $dbName . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
} catch (\Throwable $e) {
    error_log('[Neria emergency] Connexion DB échouée : ' . $e->getMessage());
    emergencyDie(e18n($T, 'db_connection_failed'), $T, $emergencyDir, $emergencyLang);
}

// ── Validation du token ───────────────────────────────────────────
$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '') {
    emergencyDie(e18n($T, 'token_missing')
        . '<br><small>' . e18n($T, 'token_missing_hint') . '</small>', $T, $emergencyDir, $emergencyLang);
}

try {
    $stmt = $pdo->prepare(
        "SELECT `value` FROM `{$prefix}configuration`
         WHERE `name` = 'NERIA_EMERGENCY_TOKEN' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $validToken = $row ? (string) $row['value'] : '';
} catch (Exception $e) {
    error_log('[Neria emergency] Lecture token échouée : ' . $e->getMessage());
    emergencyDie(e18n($T, 'token_read_error'), $T, $emergencyDir, $emergencyLang);
}

if ($validToken === '' || !hash_equals($validToken, $token)) {
    // Simuler un délai pour ralentir le bruteforce
    sleep(2);
    emergencyDie(e18n($T, 'token_invalid')
        . '<br><small>' . e18n($T, 'token_invalid_hint') . '</small>', $T, $emergencyDir, $emergencyLang);
}

// ── Lecture des données ───────────────────────────────────────────

// Derniers 100 logs watchdog
try {
    $stmt = $pdo->prepare(
        "SELECT `level`, `template`, `class`, `message`, `date_add`
         FROM `{$prefix}neria_log`
         ORDER BY `date_add` DESC
         LIMIT 100"
    );
    $stmt->execute();
    $logs = $stmt->fetchAll();
} catch (Exception $e) {
    // Round 166 : contrairement à tous les autres blocs de lecture de ce
    // fichier (health checks, bounces, compteurs — qui avalent leur erreur
    // silencieusement), celui-ci affichait $e->getMessage() en clair dans
    // la page APRÈS authentification par token — potentiellement le nom du
    // driver SQL, la structure de la requête, le préfixe de table réel.
    // Incohérent avec le principe explicite du fichier ("aucun détail
    // technique renvoyé à l'appelant") : le détail va désormais uniquement
    // au log serveur, comme partout ailleurs dans cette page.
    error_log('[Neria emergency] Lecture des logs échouée : ' . $e->getMessage());
    $logs = [];
    $logsError = true;
}

// Derniers résultats de santé
try {
    $stmt = $pdo->prepare(
        "SELECT `value` FROM `{$prefix}configuration`
         WHERE `name` = 'NERIA_HEALTH_RESULTS' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $healthResults = $row ? json_decode((string) $row['value'], true) : [];
} catch (Exception $e) {
    $healthResults = [];
}

// Dernière exécution du diagnostic
try {
    $stmt = $pdo->prepare(
        "SELECT `value` FROM `{$prefix}configuration`
         WHERE `name` = 'NERIA_HEALTH_LAST_RUN' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $healthLastRun = $row ? (string) $row['value'] : '';
} catch (Exception $e) {
    $healthLastRun = '';
}

// Compteur d'échecs consécutifs
try {
    $stmt = $pdo->prepare(
        "SELECT `value` FROM `{$prefix}configuration`
         WHERE `name` = 'NERIA_CONSECUTIVE_FAILURES' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $consecutiveFailures = $row ? (int) $row['value'] : 0;
} catch (Exception $e) {
    $consecutiveFailures = 0;
}

// Nombre de bounces actifs
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS n FROM `{$prefix}neria_bounces` WHERE `status` = 'active'"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $activeBounces = $row ? (int) $row['n'] : 0;
} catch (Exception $e) {
    $activeBounces = 0;
}

// Comptes par niveau de log
$logCounts = ['info' => 0, 'warning' => 0, 'error' => 0, 'critical' => 0];
foreach ($logs as $l) {
    $lv = $l['level'] ?? 'info';
    if (isset($logCounts[$lv])) {
        $logCounts[$lv]++;
    }
}

$elapsed = round((microtime(true) - $startTime) * 1000);

// ── Rendu HTML ────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($emergencyLang) ?>" dir="<?= htmlspecialchars($emergencyDir) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Neria — <?= htmlspecialchars(e18n($T, 'title_suffix')) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 13px; background: #f5f5f5; color: #333; }
.header { background: #1a1a2e; color: #fff; padding: 16px 24px; display: flex; align-items: center; gap: 16px; }
.header__logo { font-size: 20px; color: #b38b59; }
.header__title { font-size: 18px; font-weight: 600; }
.header__sub { font-size: 12px; color: #aaa; margin-top: 2px; }
.header__badge { margin-left: auto; background: #b38b59; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
.alert--warn { background: #fff8e6; border: 1px solid #e6a817; color: #7a5500; }
.alert--ok { background: #f0faf0; border: 1px solid #4caf50; color: #1b5e20; }
.section { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 20px; overflow: hidden; }
.section__head { padding: 14px 20px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 10px; }
.section__title { font-size: 14px; font-weight: 600; color: #1a1a2e; }
.section__body { padding: 20px; }
.kpi-row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 0; }
.kpi { flex: 1; min-width: 120px; background: #f9f9f9; border: 1px solid #eee; border-radius: 6px; padding: 14px 16px; text-align: center; }
.kpi__val { font-size: 28px; font-weight: 700; line-height: 1; }
.kpi__lbl { font-size: 11px; color: #888; margin-top: 4px; }
.kpi--info .kpi__val { color: #1976d2; }
.kpi--warn .kpi__val { color: #ba7517; }
.kpi--err .kpi__val { color: #a32d2d; }
.kpi--crit .kpi__val { color: #7a0000; }
.kpi--bounce .kpi__val { color: #b38b59; }
.kpi--fail .kpi__val { color: <?= $consecutiveFailures >= 3 ? '#a32d2d' : ($consecutiveFailures > 0 ? '#ba7517' : '#4caf50') ?>; }
.health-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.hcard { border-radius: 6px; padding: 12px 14px; border: 1px solid; }
.hcard--ok { background: #f0faf0; border-color: #c8e6c9; }
.hcard--warning { background: #fff8e6; border-color: #ffe082; }
.hcard--error { background: #fff0f0; border-color: #ffcdd2; }
.hcard__key { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; color: #555; }
.hcard__detail { font-size: 12px; color: #444; line-height: 1.5; }
.hcard__action { display: block; margin-top: 6px; color: #ba7517; font-weight: 600; font-size: 12px; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 12px; }
th { background: #f5f5f5; padding: 8px 10px; text-align: left; font-weight: 600; color: #555; border-bottom: 2px solid #e0e0e0; white-space: nowrap; }
td { padding: 7px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
tr:hover td { background: #fafafa; }
.badge { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.badge--info { background: #e3f2fd; color: #1565c0; }
.badge--warning { background: #fff8e6; color: #ba7517; border: 1px solid #ffe082; }
.badge--error { background: #ffebee; color: #a32d2d; border: 1px solid #ffcdd2; }
.badge--critical { background: #7a0000; color: #fff; }
.msg { max-width: 600px; word-break: break-word; line-height: 1.5; }
.msg-action { display: block; color: #ba7517; font-weight: 600; margin-top: 3px; }
.footer { text-align: center; color: #aaa; font-size: 11px; padding: 20px; }
.filter-row { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
.filter-row select, .filter-row input { padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; }
.no-data { color: #aaa; text-align: center; padding: 30px; }
</style>
</head>
<body>

<div class="header">
  <span class="header__logo">✦</span>
  <div>
    <div class="header__title">Neria — <?= htmlspecialchars(e18n($T, 'title_suffix')) ?></div>
    <div class="header__sub"><?= htmlspecialchars(e18n($T, 'header_sub', $dbName, $elapsed)) ?></div>
  </div>
  <span class="header__badge"><?= htmlspecialchars(e18n($T, 'badge_emergency')) ?></span>
</div>

<div class="container">

  <div class="alert alert--warn">
    ⚠ <?= e18n($T, 'alert_warn_body') ?>
  </div>

  <?php if ($consecutiveFailures >= 3): ?>
  <div class="alert alert--warn">
    🔴 <strong><?= htmlspecialchars(e18n($T, 'consecutive_failures_alert', $consecutiveFailures)) ?></strong>
    <?= e18n($T, 'consecutive_failures_note') ?>
  </div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="section">
    <div class="section__head">
      <span class="section__title"><?= htmlspecialchars(e18n($T, 'section_overview')) ?></span>
    </div>
    <div class="section__body">
      <div class="kpi-row">
        <div class="kpi kpi--info">
          <div class="kpi__val"><?= $logCounts['info'] ?></div>
          <div class="kpi__lbl">INFO</div>
        </div>
        <div class="kpi kpi--warn">
          <div class="kpi__val"><?= $logCounts['warning'] ?></div>
          <div class="kpi__lbl">WARNING</div>
        </div>
        <div class="kpi kpi--err">
          <div class="kpi__val"><?= $logCounts['error'] ?></div>
          <div class="kpi__lbl">ERROR</div>
        </div>
        <div class="kpi kpi--crit">
          <div class="kpi__val"><?= $logCounts['critical'] ?></div>
          <div class="kpi__lbl">CRITICAL</div>
        </div>
        <div class="kpi kpi--fail">
          <div class="kpi__val"><?= $consecutiveFailures ?></div>
          <div class="kpi__lbl"><?= htmlspecialchars(e18n($T, 'kpi_consecutive_failures')) ?></div>
        </div>
        <div class="kpi kpi--bounce">
          <div class="kpi__val"><?= $activeBounces ?></div>
          <div class="kpi__lbl"><?= htmlspecialchars(e18n($T, 'kpi_active_bounces')) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Contrôles de santé -->
  <?php if (!empty($healthResults)): ?>
  <div class="section">
    <div class="section__head">
      <span class="section__title"><?= htmlspecialchars(e18n($T, 'section_health_checks')) ?></span>
      <?php if ($healthLastRun): ?>
        <span style="font-size:11px;color:#aaa;margin-left:auto;"><?= htmlspecialchars(e18n($T, 'last_diagnostic', $healthLastRun)) ?></span>
      <?php endif; ?>
    </div>
    <div class="section__body">
      <div class="health-grid">
        <?php foreach ($healthResults as $key => $result):
          $status = $result['status'] ?? 'ok';
          $detail = $result['detail'] ?? '';
          $parts  = explode('→ Que faire :', $detail, 2);
          $fact   = trim($parts[0]);
          $action = isset($parts[1]) ? trim($parts[1]) : '';
        ?>
        <div class="hcard hcard--<?= htmlspecialchars($status) ?>">
          <div class="hcard__key"><?= htmlspecialchars(str_replace('_', ' ', $key)) ?></div>
          <div class="hcard__detail">
            <?= htmlspecialchars($fact) ?>
            <?php if ($action): ?>
              <span class="hcard__action">→ Que faire : <?= htmlspecialchars($action) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Journal -->
  <div class="section">
    <div class="section__head">
      <span class="section__title"><?= htmlspecialchars(e18n($T, 'section_log')) ?></span>
    </div>
    <div class="section__body">

      <div class="filter-row">
        <select id="fLevel" onchange="filterLogs()">
          <option value=""><?= htmlspecialchars(e18n($T, 'filter_all_levels')) ?></option>
          <option value="info">INFO</option>
          <option value="warning">WARNING</option>
          <option value="error">ERROR</option>
          <option value="critical">CRITICAL</option>
        </select>
        <input type="text" id="fText" placeholder="<?= htmlspecialchars(e18n($T, 'filter_placeholder')) ?>" oninput="filterLogs()" style="min-width:240px;">
      </div>

      <?php if (empty($logs)): ?>
        <div class="no-data"><?= htmlspecialchars(e18n($T, 'no_logs')) ?><?= isset($logsError) ? ' — ' . htmlspecialchars(e18n($T, 'token_read_error')) : '' ?></div>
      <?php else: ?>
      <div class="table-wrap">
        <table id="logTable">
          <thead>
            <tr>
              <th><?= htmlspecialchars(e18n($T, 'th_date')) ?></th>
              <th><?= htmlspecialchars(e18n($T, 'th_level')) ?></th>
              <th><?= htmlspecialchars(e18n($T, 'th_class')) ?></th>
              <th><?= htmlspecialchars(e18n($T, 'th_template')) ?></th>
              <th><?= htmlspecialchars(e18n($T, 'th_message')) ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($logs as $log):
            $lv      = $log['level'] ?? 'info';
            $msg     = $log['message'] ?? '';
            $parts   = explode('→ Que faire :', $msg, 2);
            $msgMain = trim($parts[0]);
            $msgAct  = isset($parts[1]) ? trim($parts[1]) : '';
          ?>
            <tr data-level="<?= htmlspecialchars($lv) ?>"
                data-text="<?= htmlspecialchars(strtolower($msg . ' ' . ($log['class'] ?? ''))) ?>">
              <td style="white-space:nowrap;"><?= htmlspecialchars(substr($log['date_add'] ?? '', 0, 16)) ?></td>
              <td><span class="badge badge--<?= htmlspecialchars($lv) ?>"><?= htmlspecialchars(strtoupper($lv)) ?></span></td>
              <td style="white-space:nowrap;"><?= htmlspecialchars($log['class'] ?? '—') ?></td>
              <td style="white-space:nowrap;"><?= htmlspecialchars($log['template'] ?? '—') ?></td>
              <td class="msg">
                <?= htmlspecialchars($msgMain) ?>
                <?php if ($msgAct): ?>
                  <span class="msg-action">→ Que faire : <?= htmlspecialchars($msgAct) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

    </div>
  </div>

</div>

<div class="footer">
  Neria Emergency Watchdog v<?= NERIA_EMERGENCY_VERSION ?> —
  <?= htmlspecialchars(e18n($T, 'footer_line')) ?>
  <a href="?token=<?= htmlspecialchars(urlencode($token)) ?>&lang=<?= htmlspecialchars(urlencode($emergencyLang)) ?>" style="color:#b38b59;"><?= htmlspecialchars(e18n($T, 'refresh')) ?></a>
</div>

<script>
function filterLogs() {
  var level = document.getElementById('fLevel').value.toLowerCase();
  var text  = document.getElementById('fText').value.toLowerCase();
  document.querySelectorAll('#logTable tbody tr').forEach(function(tr) {
    var lvMatch  = !level || tr.dataset.level === level;
    var txtMatch = !text  || tr.dataset.text.indexOf(text) !== -1;
    tr.style.display = (lvMatch && txtMatch) ? '' : 'none';
  });
}
</script>

</body>
</html>
<?php

// ── Fonctions utilitaires ─────────────────────────────────────────

function emergencyDie(string $html, array $T = [], string $dir = 'ltr', string $lang = 'en'): void
{
    $title = $T ? e18n($T, 'access_denied_title') : 'Access denied';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="' . htmlspecialchars($lang) . '" dir="' . htmlspecialchars($dir) . '"><head><meta charset="utf-8">
    <title>Neria — ' . htmlspecialchars($title) . '</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;
    min-height:100vh;background:#f5f5f5;margin:0;}
    .box{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:40px;
    max-width:520px;text-align:center;}
    .logo{font-size:32px;color:#b38b59;margin-bottom:16px;}
    h1{font-size:18px;color:#1a1a2e;margin-bottom:12px;}
    p{font-size:13px;color:#666;line-height:1.6;}</style>
    </head><body><div class="box">
    <div class="logo">✦</div>
    <h1>Neria Emergency Watchdog</h1>
    <p>' . $html . '</p>
    </div></body></html>';
    exit;
}
