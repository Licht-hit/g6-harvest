<?php
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
if ($user) {
    redirect(app_url(dashboard_path_for_role($user['role'])));
}

$isBuyer = $user && $user['role'] === 'buyer';
$favoriteIds = [];
$contactEmail = getenv('CONTACT_EMAIL');
$contactEmail = is_string($contactEmail) && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)
    ? $contactEmail
    : '';

if ($isBuyer) {
    $favoriteStatement = $db->prepare(
        'SELECT vegetable_id FROM buyer_favorites WHERE buyer_id = ?'
    );
    $favoriteStatement->execute([(int) $user['id']]);
    $favoriteIds = array_fill_keys(
        array_map('intval', array_column($favoriteStatement->fetchAll(), 'vegetable_id')),
        true
    );
}

$featuredVegetables = $db->query(
    'SELECT
        v.id,
        v.name,
        v.category,
        v.image_path,
        v.variety,
        v.description,
        v.unit,
        v.price,
        COALESCE(i.quantity, 0) AS stock
     FROM vegetables AS v
     LEFT JOIN inventory AS i ON i.vegetable_id = v.id
     WHERE v.active = 1
     ORDER BY v.name
     LIMIT 6'
)->fetchAll();

$publicStats = $db->query(
    'SELECT
        (SELECT COUNT(*) FROM vegetables WHERE active = 1) AS vegetable_count,
        (SELECT COUNT(*) FROM vegetables AS v
            JOIN inventory AS i ON i.vegetable_id = v.id
            WHERE v.active = 1 AND i.quantity > 0) AS available_count,
        (SELECT COUNT(*) FROM orders WHERE order_status = \'delivered\') AS completed_orders,
        (SELECT COUNT(*) FROM harvests WHERE archived_at IS NULL) AS harvest_count'
)->fetch();

$heroCarrot = product_picture('Carrot');
$heroTomato = product_picture('Tomato');
$heroZucchini = product_picture('Zucchini');
$pageTitle = 'Fresh vegetables from the farm';
$pageDescription = 'Explore Harvest’s live vegetable catalog, see current prices and availability, and learn how the farm shop connects produce with customer orders.';
$pageClass = 'public-home-page';
require __DIR__ . '/includes/header.php';
?>
<section class="public-hero" id="home" aria-labelledby="public-hero-title">
    <div class="public-hero-copy" data-reveal>
        <p class="public-eyebrow"><span aria-hidden="true"></span> From our farm to your table</p>
        <h1 id="public-hero-title">A little more <em>goodness</em> in every day.</h1>
        <p class="public-hero-intro">
            Get to know the produce in our live farm catalog and find fresh picks for your table.
        </p>
        <div class="public-hero-actions">
            <a class="public-button" href="#featured">Shop the harvest <span aria-hidden="true">→</span></a>
            <a class="public-text-link" href="#about">Learn more <span aria-hidden="true">↗</span></a>
        </div>
        <p class="public-hero-footnote">
            <i data-lucide="leaf" aria-hidden="true"></i>
            Prices and availability are shown from the current catalog.
        </p>
    </div>

    <div class="public-hero-art" data-reveal aria-label="Fresh vegetable photography">
        <span class="public-hero-orbit public-hero-orbit--one" aria-hidden="true"></span>
        <span class="public-hero-orbit public-hero-orbit--two" aria-hidden="true"></span>
        <figure class="public-hero-photo public-hero-photo--main">
            <?php if ($heroCarrot['url']): ?>
                <img
                    src="<?= h($heroCarrot['url']) ?>"
                    alt="A colorful bunch of freshly picked carrots"
                    fetchpriority="high"
                >
            <?php else: ?>
                <span class="public-hero-photo-fallback" aria-hidden="true"><?= h($heroCarrot['icon']) ?></span>
            <?php endif; ?>
        </figure>
        <figure class="public-hero-photo public-hero-photo--tomato" aria-hidden="true">
            <?php if ($heroTomato['url']): ?>
                <img src="<?= h($heroTomato['url']) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
                <span><?= h($heroTomato['icon']) ?></span>
            <?php endif; ?>
        </figure>
        <figure class="public-hero-photo public-hero-photo--greens" aria-hidden="true">
            <?php if ($heroZucchini['url']): ?>
                <img src="<?= h($heroZucchini['url']) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
                <span><?= h($heroZucchini['icon']) ?></span>
            <?php endif; ?>
        </figure>
        <span class="public-hero-photo-label"><span aria-hidden="true">✳</span> A fresh look at the harvest</span>
    </div>

    <aside class="public-hero-highlights" aria-label="Shopping highlights" data-reveal>
        <p class="public-highlight-heading">Good things, made simple</p>
        <article class="public-highlight">
            <span class="public-highlight-icon"><i data-lucide="leaf"></i></span>
            <span><strong>See what is available</strong><small>Check produce and stock from the live catalog.</small></span>
        </article>
        <article class="public-highlight">
            <span class="public-highlight-icon"><i data-lucide="circle-dollar-sign"></i></span>
            <span><strong>Know what you are choosing</strong><small>Browse item details, prices, and units.</small></span>
        </article>
        <article class="public-highlight">
            <span class="public-highlight-icon"><i data-lucide="package-check"></i></span>
            <span><strong>Order when you are ready</strong><small>Create an account to save favorites and shop.</small></span>
        </article>
    </aside>
</section>

<section class="public-trust-strip" aria-label="Harvest at a glance" data-reveal>
    <div><strong>Live catalog</strong><span>Explore produce currently listed by the farm.</span></div>
    <div><strong>Clear availability</strong><span>Check current stock before you decide.</span></div>
    <div><strong>Your favorites</strong><span>Save regular picks to a buyer account.</span></div>
</section>

<section class="public-section public-featured" id="featured" aria-labelledby="featured-title">
    <div class="public-section-heading" data-reveal>
        <div>
            <p class="public-eyebrow">From the current catalog</p>
            <h2 id="featured-title">Meet the fresh picks.</h2>
        </div>
        <p>Every item below comes from the farm's active produce catalog.</p>
    </div>

    <?php if ($featuredVegetables): ?>
        <div class="public-product-carousel">
            <div class="swiper public-product-swiper">
                <div class="swiper-wrapper">
            <?php foreach ($featuredVegetables as $vegetable): ?>
                <?php
                $picture = product_picture($vegetable['name'], $vegetable['image_path']);
                $inStock = (float) $vegetable['stock'] > 0;
                $isFavorite = isset($favoriteIds[(int) $vegetable['id']]);
                $description = trim((string) ($vegetable['description'] ?? ''));
                ?>
                <article class="public-product-card swiper-slide" data-reveal>
                    <div class="public-product-image product-image">
                        <?php if ($picture['url']): ?>
                            <img
                                src="<?= h($picture['url']) ?>"
                                alt="<?= h($vegetable['name']) ?>"
                                loading="lazy"
                                decoding="async"
                            >
                        <?php endif; ?>
                        <?php if (!$picture['url']): ?>
                            <span class="public-product-fallback" aria-hidden="true"><?= h($picture['icon']) ?></span>
                        <?php endif; ?>
                        <span class="public-stock-badge<?= $inStock ? '' : ' is-unavailable' ?>">
                            <?= $inStock ? 'In stock' : 'Currently unavailable' ?>
                        </span>
                        <?php if ($isBuyer): ?>
                            <form method="post" action="<?= h(app_url('buyer/favorites.php')) ?>" class="public-favorite-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="<?= $isFavorite ? 'remove' : 'save' ?>">
                                <input type="hidden" name="vegetable_id" value="<?= (int) $vegetable['id'] ?>">
                                <input type="hidden" name="return_to" value="home">
                                <button
                                    class="public-favorite-button<?= $isFavorite ? ' is-favorite' : '' ?>"
                                    type="submit"
                                    aria-label="<?= $isFavorite ? 'Remove ' . h($vegetable['name']) . ' from favorites' : 'Save ' . h($vegetable['name']) . ' to favorites' ?>"
                                    aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"
                                    title="<?= $isFavorite ? 'Remove from favorites' : 'Save to favorites' ?>"
                                ><i data-lucide="heart" aria-hidden="true"></i></button>
                            </form>
                        <?php else: ?>
                            <a
                                class="public-favorite-button"
                                href="<?= h(app_url('auth/login.php')) ?>"
                                aria-label="Log in to save <?= h($vegetable['name']) ?> to favorites"
                                title="Log in to save favorites"
                            ><i data-lucide="heart" aria-hidden="true"></i></a>
                        <?php endif; ?>
                    </div>
                    <div class="public-product-copy">
                        <div class="public-product-heading">
                            <div>
                                <p><?= h($vegetable['category']) ?></p>
                                <h3><?= h($vegetable['name']) ?></h3>
                            </div>
                            <strong><?= money($vegetable['price']) ?><small> / <?= h($vegetable['unit']) ?></small></strong>
                        </div>
                        <p class="public-product-description">
                            <?= $description !== '' ? h($description) : 'Explore this item in the current Harvest catalog.' ?>
                        </p>
                        <?php if (!empty($vegetable['variety'])): ?>
                            <p class="public-product-variety">Variety: <?= h($vegetable['variety']) ?></p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
                </div>
            </div>
            <div class="public-products-controls" aria-label="Featured produce carousel controls">
                <button class="public-slider-arrow" type="button" data-products-previous aria-label="Show previous produce">
                    <i data-lucide="chevron-left"></i>
                </button>
                <div class="public-slider-pagination" data-products-pagination></div>
                <button class="public-slider-arrow" type="button" data-products-next aria-label="Show more produce">
                    <i data-lucide="chevron-right"></i>
                </button>
            </div>
        </div>
        <div class="public-products-cta" data-reveal>
            <p>Ready to choose your produce?</p>
            <a class="public-button public-button-small" href="<?= h(app_url($user ? 'buyer/index.php' : 'auth/register.php')) ?>">
                <?= $user ? 'Open the farm shop' : 'Create an account to shop' ?> <span aria-hidden="true">→</span>
            </a>
        </div>
    <?php else: ?>
        <div class="public-empty-state" role="status">
            <span aria-hidden="true">🌱</span>
            <h3>The next harvest is on its way.</h3>
            <p>There are no active produce items in the catalog just yet. Please check back soon.</p>
        </div>
    <?php endif; ?>
</section>

<section class="public-about public-section" id="about" aria-labelledby="about-title">
    <div class="public-about-art" data-reveal>
        <?php $aboutPicture = product_picture('Kale'); ?>
        <?php if ($aboutPicture['url']): ?>
            <img src="<?= h($aboutPicture['url']) ?>" alt="Green leafy produce from the Harvest catalog" loading="lazy" decoding="async">
        <?php else: ?>
            <span aria-hidden="true"><?= h($aboutPicture['icon']) ?></span>
        <?php endif; ?>
        <span class="public-about-stamp"><span aria-hidden="true">✳</span> Growing good things together</span>
    </div>
    <div class="public-about-copy" data-reveal>
        <p class="public-eyebrow">A closer connection to the farm</p>
        <h2 id="about-title">A farm story, from planting to <em>your table.</em></h2>
        <p>Harvest brings farm operations and produce ordering together. Staff can record plantings and harvests, track inventory, and share current availability with shoppers.</p>
        <p>Customers can explore real catalog details, save favorite produce, and manage orders through their own account.</p>
        <a class="public-arrow-link" href="#farming-process">See how Harvest works <span aria-hidden="true">→</span></a>
    </div>
</section>

<section class="public-why public-section" id="why-harvest" aria-labelledby="why-title">
    <div class="public-section-heading" data-reveal>
        <div>
            <p class="public-eyebrow">The Harvest difference</p>
            <h2 id="why-title">Farm details, made easier to find.</h2>
        </div>
        <p>A connected view of produce, availability, and ordering.</p>
    </div>
    <div class="public-feature-grid">
        <article class="public-feature-card" data-reveal>
            <span><i data-lucide="leaf"></i></span>
            <h3>Current catalog</h3>
            <p>Explore active produce items and see the prices and units listed by the farm.</p>
        </article>
        <article class="public-feature-card" data-reveal>
            <span><i data-lucide="store"></i></span>
            <h3>Clear availability</h3>
            <p>Inventory information helps you understand what is in stock before you order.</p>
        </article>
        <article class="public-feature-card" data-reveal>
            <span><i data-lucide="heart"></i></span>
            <h3>Favorites that stay yours</h3>
            <p>Save produce to your buyer account and find your regular picks again.</p>
        </article>
        <article class="public-feature-card" data-reveal>
            <span><i data-lucide="shopping-basket"></i></span>
            <h3>Order management</h3>
            <p>Use your customer account to place orders and follow their status.</p>
        </article>
    </div>
</section>

<section class="public-process public-section" id="farming-process" aria-labelledby="process-title">
    <div class="public-section-heading" data-reveal>
        <div>
            <p class="public-eyebrow">From farm records to fresh picks</p>
            <h2 id="process-title">A little more connected at every step.</h2>
        </div>
        <p>Harvest helps bring the farm's records and customer shop into one place.</p>
    </div>
    <ol class="public-process-grid">
        <li data-reveal><span>01</span><h3>Plan</h3><p>Staff record what is planted and when it is expected to grow.</p></li>
        <li data-reveal><span>02</span><h3>Harvest</h3><p>Harvest records keep quantities and dates together.</p></li>
        <li data-reveal><span>03</span><h3>Track</h3><p>Inventory shows the produce currently listed as available.</p></li>
        <li data-reveal><span>04</span><h3>Choose</h3><p>Customers browse the catalog and place orders from their account.</p></li>
    </ol>
</section>

<section class="public-stats public-section" aria-label="Harvest statistics" data-reveal>
    <div><strong><?= number_format((int) $publicStats['vegetable_count']) ?></strong><span>Active produce items</span></div>
    <div><strong><?= number_format((int) $publicStats['available_count']) ?></strong><span>Items in stock</span></div>
    <div><strong><?= number_format((int) $publicStats['completed_orders']) ?></strong><span>Delivered orders</span></div>
    <div><strong><?= number_format((int) $publicStats['harvest_count']) ?></strong><span>Harvest records</span></div>
</section>

<section class="public-contact public-section" id="contact" aria-labelledby="contact-title">
    <div data-reveal>
        <p class="public-eyebrow">We're here to help</p>
        <h2 id="contact-title">Questions about your next order?</h2>
        <p>Sign in to manage an order, or create a buyer account to start browsing and save produce you love.</p>
    </div>
    <div class="public-contact-actions" data-reveal>
        <?php if ($contactEmail): ?>
            <a class="public-contact-email" href="mailto:<?= h($contactEmail) ?>"><?= h($contactEmail) ?></a>
        <?php endif; ?>
        <a class="public-button" href="<?= h(app_url($user ? 'buyer/dashboard.php' : 'auth/register.php')) ?>">
            <?= $user ? 'Go to your dashboard' : 'Create a buyer account' ?> <span aria-hidden="true">→</span>
        </a>
        <?php if (!$user): ?>
            <a class="public-text-link" href="<?= h(app_url('auth/login.php')) ?>">Already have an account? Log in</a>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
