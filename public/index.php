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
                    <p class="eyebrow">Your measurements. A better fit.</p>
                    <h1 id="hero-title">Custom stitching.<br>Made simple.</h1>
                    <p>Your fabric, your fit, your style. Find a tailor, share your preferences and follow every stage of your stitching journey.</p>
                    <div class="hero-actions">
                        <a class="button" href="/customer/tailors.php">Request stitching <span aria-hidden="true">→</span></a>
                        <a href="/register.php?role=tailor">Join as a tailor</a>
                    </div>
                </div>
                <img class="hero-image" src="/assets/images/tailoring-hero.jpg" alt="Brass tailoring scissors, measuring tape and thread on navy fabric" width="1200" height="800" fetchpriority="high">
            </div>
        </section>
        <section class="container section" id="how-it-works" aria-labelledby="steps-title">
            <p class="eyebrow">How it works</p>
            <h2 id="steps-title">A personal fit, in three steps.</h2>
            <div class="steps">
                <article class="step"><span class="step-number">01</span><h3>Choose your tailor</h3><p>Explore shops and their stitching services. Choose the service that suits your garment.</p></article>
                <article class="step"><span class="step-number">02</span><h3>Request a quotation</h3><p>Choose your measurements and style, then review the tailor’s price and completion date.</p></article>
                <article class="step"><span class="step-number">03</span><h3>Confirm and follow</h3><p>Accept the quotation to create your order. Follow its progress through cutting, stitching and delivery.</p></article>
            </div>
        </section>
        <section class="services-section section" id="services" aria-labelledby="services-title">
            <div class="container">
                <div class="section-heading"><div><p class="eyebrow">Made for you</p><h2 id="services-title">Stitching for your everyday.</h2></div><a href="/customer/tailors.php">Explore tailors and services →</a></div>
                <div class="service-grid">
                    <article class="service-card"><h3>Women’s stitching</h3><p>Explore kameez, shalwar kameez, kurti and abaya services offered by tailors.</p></article>
                    <article class="service-card"><h3>Men’s stitching</h3><p>Find kurta, shalwar kameez, waistcoat and trouser stitching services.</p></article>
                    <article class="service-card"><h3>Your own details</h3><p>Save neckline, sleeve and fit preferences. Share the style you want when you send a request.</p></article>
                </div>
            </div>
        </section>
        <section class="container section passport" aria-labelledby="passport-title">
            <img src="/assets/images/tailoring-hero.jpg" alt="Tailoring tools and textured fabric" width="1200" height="800" loading="lazy">
            <div><p class="eyebrow">Measurement passport</p><h2 id="passport-title">Your measurements.<br>Ready for your next garment.</h2><p>Keep reusable profiles, save new versions as your measurements change, and choose which tailors can access them.</p><a class="button" href="/register.php">Create your account →</a></div>
        </section>
    </main>
    <footer class="site-footer"><div class="container footer-content"><a class="wordmark" href="/">NaapSetu</a><p>Custom stitching. Made personal.</p><a href="#how-it-works">How it works</a></div></footer>
</body>
</html>
