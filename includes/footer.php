</main>
<?php if (!empty($pageClassNames) && in_array('public-home-page', $pageClassNames, true)): ?>
    <footer class="public-footer">
        <div class="public-footer-main">
            <div class="public-footer-brand">
                <a class="brand" href="<?= h(app_url('index.php')) ?>">
                    <span class="brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 32 32" fill="none">
                            <path d="M25.8 5.2C16 5.5 8.3 8.8 8.3 17.1c0 3.2 2.2 5.4 5.1 5.4 8.8 0 11.6-8.8 12.4-17.3Z" fill="currentColor"/>
                            <path d="M5.2 27c4.6-7.5 9.2-11 16.3-15.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <span>Harvest<span class="brand-period">.</span></span>
                </a>
                <p>A farm operations and produce shop connecting planting, inventory, and customer orders.</p>
            </div>
            <nav class="public-footer-links" aria-label="Footer navigation">
                <strong>Explore</strong>
                <a href="<?= h(app_url('index.php#featured')) ?>">Featured produce</a>
                <a href="<?= h(app_url('index.php#about')) ?>">About Harvest</a>
                <a href="<?= h(app_url('index.php#farming-process')) ?>">How it works</a>
            </nav>
            <nav class="public-footer-links" aria-label="Account links">
                <strong>Your account</strong>
                <a href="<?= h(app_url('auth/login.php')) ?>">Log in</a>
                <a href="<?= h(app_url('auth/register.php')) ?>">Create account</a>
                <?php if (!empty($contactEmail)): ?>
                    <a href="mailto:<?= h($contactEmail) ?>"><?= h($contactEmail) ?></a>
                <?php endif; ?>
            </nav>
        </div>
        <div class="public-footer-bottom">
            <p>&copy; <?= date('Y') ?> Harvest. Farm operations and produce sales.</p>
            <a href="#home">Back to top ↑</a>
        </div>
    </footer>
<?php else: ?>
<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> Harvest. Farm operations and produce sales.</p>
</footer>
<?php endif; ?>
</body>
</html>
