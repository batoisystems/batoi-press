<?php
declare(strict_types=1);
?>
<article class="bp-page bp-contact-page">
    <?php echo $page['body'] ?? ''; ?>
    <?php if (($contact['status'] ?? '') === 'sent'): ?><p class="bp-notice" role="status">Thank you. Your message has been sent.</p><?php endif; ?>
    <form class="bp-contact-form" method="post" action="<?php echo bp_attr(bp_url('/contact/submit')); ?>">
        <input type="hidden" name="contact_page" value="<?php echo bp_attr((string)($page['slug'] ?? 'contact')); ?>">
        <div class="bp-field-grid"><label>Name <input type="text" name="name" maxlength="120" autocomplete="name" required></label><label>Email <input type="email" name="email" maxlength="254" autocomplete="email" required></label></div>
        <label>Subject <input type="text" name="subject" maxlength="160"></label>
        <label>Message <textarea name="message" rows="8" maxlength="10000" required></textarea></label>
        <label class="bp-honeypot" aria-hidden="true">Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        <?php if (!empty($contact['recaptcha_site_key'])): ?><div class="g-recaptcha" data-sitekey="<?php echo bp_attr((string)$contact['recaptcha_site_key']); ?>"></div><script src="https://www.google.com/recaptcha/api.js" async defer></script><?php endif; ?>
        <button class="bp-button" type="submit">Send message</button>
    </form>
</article>
