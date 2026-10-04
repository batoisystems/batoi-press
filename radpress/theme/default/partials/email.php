<?php
declare(strict_types=1);
/** Contact email: $name, $email, $subject, $message, $site and $escape. */
$siteName = trim((string)($site['name'] ?? '')) ?: 'Batoi Press';
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Website contact</title></head>
<body style="margin:0;padding:24px 12px;background:#f3f4f6;color:#111827;font-family:proxima-nova,Arial,sans-serif;line-height:1.5">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="620" cellpadding="0" cellspacing="0" style="width:100%;max-width:620px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px">
<tr><td style="padding:24px 30px;border-bottom:1px solid #e5e7eb;color:#0E68B0;font-size:18px;font-weight:600"><?php echo $escape($siteName); ?></td></tr>
<tr><td style="padding:26px 30px">
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.3">Website contact</h1>
<p><strong>Name:</strong> <?php echo $escape($name); ?><br><strong>Email:</strong> <?php echo $escape($email); ?></p>
<p><?php echo nl2br($escape($message), false); ?></p>
</td></tr>
<tr><td style="padding:18px 30px;background:#f9fafb;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px">Sent from <?php echo $escape($siteName); ?> · Powered by Batoi Press</td></tr>
</table>
</td></tr></table>
</body>
</html>
