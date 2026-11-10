import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { createIcons, ArrowRight, ChevronLeft, ChevronRight, CircleDollarSign, Heart, Leaf, PackageCheck, ShoppingBasket, Sprout, Store } from 'lucide';
import Swiper from 'swiper';
import { A11y, Keyboard, Navigation, Pagination } from 'swiper/modules';
import 'swiper/css';
import 'swiper/css/a11y';
import 'swiper/css/navigation';
import 'swiper/css/pagination';

createIcons({
    icons: {
        ArrowRight,
        ChevronLeft,
        ChevronRight,
        CircleDollarSign,
        Heart,
        Leaf,
        PackageCheck,
        ShoppingBasket,
        Sprout,
        Store,
    },
    attrs: {
        'aria-hidden': 'true',
        'stroke-width': 1.7,
    },
});

const productCarousel = document.querySelector('.public-product-swiper');
if (productCarousel instanceof HTMLElement) {
    const previousButton = document.querySelector('[data-products-previous]');
    const nextButton = document.querySelector('[data-products-next]');
    const pagination = document.querySelector('[data-products-pagination]');

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    new Swiper(productCarousel, {
        modules: [A11y, Keyboard, Navigation, Pagination],
        slidesPerView: 1.08,
        spaceBetween: 12,
        speed: reduceMotion ? 0 : 350,
        watchOverflow: true,
        grabCursor: true,
        keyboard: {
            enabled: true,
            onlyInViewport: true,
        },
        navigation: {
            prevEl: previousButton,
            nextEl: nextButton,
        },
        pagination: {
            el: pagination,
            clickable: true,
        },
        a11y: {
            enabled: true,
            prevSlideMessage: 'Show previous produce',
            nextSlideMessage: 'Show more produce',
            slideLabelMessage: 'Produce item {{index}} of {{slidesLength}}',
        },
        breakpoints: {
            520: {
                slidesPerView: 2,
                spaceBetween: 16,
            },
            900: {
                slidesPerView: 3,
                spaceBetween: 18,
            },
        },
    });
}

gsap.registerPlugin(ScrollTrigger);

if (document.body.classList.contains('public-home-page')) {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    if (!reduceMotion.matches) {
        document.body.classList.add('motion-ready');

        gsap.utils.toArray('[data-reveal]').forEach((item) => {
            gsap.fromTo(item, {
                autoAlpha: 0,
                y: 18,
            }, {
                autoAlpha: 1,
                y: 0,
                duration: 0.62,
                ease: 'power2.out',
                clearProps: 'transform',
                scrollTrigger: {
                    trigger: item,
                    start: 'top 88%',
                    once: true,
                },
                onComplete: () => item.classList.add('is-visible'),
            });
        });

        gsap.utils.toArray('.public-hero-photo--tomato, .public-hero-photo--greens').forEach((item, index) => {
            gsap.to(item, {
                y: index === 0 ? -9 : 8,
                rotation: index === 0 ? 3 : -2,
                duration: 3.8 + index * 0.65,
                ease: 'sine.inOut',
                repeat: -1,
                yoyo: true,
                delay: index * 0.25,
            });
        });

        reduceMotion.addEventListener('change', (event) => {
            if (event.matches) {
                ScrollTrigger.getAll().forEach((trigger) => trigger.kill());
                gsap.killTweensOf('[data-reveal], .public-hero-photo--tomato, .public-hero-photo--greens');
                document.body.classList.remove('motion-ready');
                document.querySelectorAll('[data-reveal]').forEach((item) => {
                    item.classList.add('is-visible');
                    gsap.set(item, { clearProps: 'all' });
                });
            }
        });
    } else {
        document.querySelectorAll('[data-reveal]').forEach((item) => item.classList.add('is-visible'));
    }
}
