<?php

declare(strict_types=1);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Find a tailor, keep your measurements, review quotations and follow your custom stitching journey with NaapSetu.">
    <title>NaapSetu — Custom stitching. Made simple.</title>
    <link rel="stylesheet" href="/assets/css/site.css">
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="site-header">
        <nav class="container nav" aria-label="Main navigation">
            <a class="wordmark" href="/" aria-label="NaapSetu home">NaapSetu</a>
            <div class="nav-links">
                <a href="/" aria-current="page">Home</a>
                <a href="#services">Services</a>
                <a href="#how-it-works">How it works</a>
                <a href="/login.php">Log in</a>
                <a class="button" href="/register.php">Sign up</a>
            </div>
        </nav>
    </header>
    <main id="main">
        <section class="hero" aria-labelledby="hero-title">
            <div class="container hero-grid">
                <div class="hero-copy">
                    <h1 id="hero-title">Custom stitching.<br>Made simple.</h1>
                    <p>Save your measurements, place stitching orders and follow their progress.</p>
                    <div class="hero-actions">
                        <a class="button" href="/customer/tailors.php">Request stitching <span aria-hidden="true">→</span></a>
                        <a href="/register.php?role=tailor">Join as a tailor</a>
                    </div>
                </div>
                <img class="hero-image" src="/assets/images/tailoring-hero.jpg" alt="Brass tailoring scissors, measuring tape and thread on navy fabric" width="1200" height="800" fetchpriority="high">
            </div>
        </section>
        <section class="container section" id="how-it-works" aria-labelledby="steps-title">
            <h2 id="steps-title" class="visually-hidden">How it works</h2>
            <div class="steps">
                <article class="step"><svg viewBox="0 0 40 40" aria-hidden="true"><rect x="5" y="12" width="30" height="20" rx="4"/><ellipse cx="20" cy="12" rx="15" ry="6"/><path d="M12 20v6m8-6v4m8-4v6"/></svg><div><h3>Choose tailor</h3><p>Find a tailor and the service you need.</p></div></article>
                <article class="step"><svg viewBox="0 0 40 40" aria-hidden="true"><path d="M10 4h15l7 8v24H10zM25 4v9h7M16 19h10m-10 6h10m-10 6h7"/></svg><div><h3>Request a quote</h3><p>Share measurements and design details.</p></div></article>
                <article class="step"><svg viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="16"/><path d="m12 20 6 6 11-12"/></svg><div><h3>Confirm your order</h3><p>Accept the quotation to start stitching.</p></div></article>
            </div>
        </section>
        <section class="services-section section" id="services" aria-labelledby="services-title">
            <div class="container">
                <div class="section-heading"><h2 id="services-title">Our services</h2></div>
                <div class="service-grid">
                    <a class="service-card fabric-navy" href="/customer/tailors.php">Kameez &amp; shirts</a>
                    <a class="service-card fabric-cream" href="/customer/tailors.php">Shalwar &amp; trousers</a>
                    <a class="service-card fabric-detail" href="/customer/tailors.php">Kurtas &amp; kurtis</a>
                    <a class="service-card fabric-dark" href="/customer/tailors.php">Waistcoats &amp; abayas</a>
                </div>
            </div>
        </section>
    </main>
    <footer class="site-footer"><div class="container footer-content"><a class="wordmark" href="/">NaapSetu</a><nav aria-label="Footer"><a href="#how-it-works">How it works</a><a href="/register.php">For customers</a><a href="/register.php?role=tailor">For tailors</a></nav><p>Custom stitching, made simple.</p></div></footer>
</body>
</html>
