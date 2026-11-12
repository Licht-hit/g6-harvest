(() => {
    const storageKey = 'harvest-buyer-language-v1';
    const translations = new Map(Object.entries({
        'Dashboard': 'Dashboard',
        'Shop': 'Mamili',
        'Favorites': 'Mga paborito',
        'My orders': 'Mga order ko',
        'Cart': 'Cart',
        'Filipino': 'Filipino',
        'English': 'English',
        'Good food, grown close to home': 'Masarap na pagkain, mula sa bukid na malapit sa tahanan',
        'Fresh from the farm': 'Sariwa mula sa bukid',
        'Fresh from our farm': 'Sariwa mula sa aming bukid',
        'Shop seasonal vegetables grown with care. Fresh picks, fair prices, and a little goodness in every basket.': 'Mamili ng mga gulay na inalagaan nang mabuti. Sariwang ani, patas na presyo, at dagdag na kabutihan sa bawat basket.',
        'View your cart': 'Tingnan ang cart mo',
        'Shop the harvest': 'Mamili ng ani',
        'Your saved produce': 'Mga naka-save mong ani',
        'Keep the produce you love close at hand.': 'Ilagay sa malapit ang mga produktong gusto mo.',
        'Browse the shop': 'Tingnan ang tindahan',
        'Browse the shop to add produce.': 'Mamili para magdagdag ng produkto.',
        'Browse fresh produce': 'Mamili ng sariwang ani',
        'From our fields to your table': 'Mula sa aming bukid papunta sa iyong hapag',
        'Good things, grown close to home': 'Sariwang ani mula sa malapit na bukid',
        'Seasonal produce': 'Pana-panahong ani',
        'Freshly harvested': 'Bagong aning gulay',
        'Picked with care': 'Maingat na pinili',
        'Support local farms': 'Suportahan ang mga lokal na bukid',
        'Good for our community': 'Mabuti para sa ating komunidad',
        'Search produce': 'Maghanap ng produkto',
        'Search vegetables...': 'Maghanap ng gulay...',
        'Category': 'Kategorya',
        'All categories': 'Lahat ng kategorya',
        'Availability': 'Availability',
        'All produce': 'Lahat ng produkto',
        'Available now': 'May stock ngayon',
        'Out of stock': 'Ubos na',
        'Sort by': 'Ayusin ayon sa',
        'Featured': 'Tampok',
        'Name: A to Z': 'Pangalan: A hanggang Z',
        'Price: Low to high': 'Presyo: pinakamababa muna',
        'Price: High to low': 'Presyo: pinakamataas muna',
        'Reset filters': 'I-reset ang mga filter',
        'Fresh pick': 'Sariwang ani',
        'In stock': 'May stock',
        'Low stock': 'Kaunti na lang',
        'Currently unavailable': 'Kasalukuyang hindi available',
        'Quantity': 'Dami',
        'Add to cart': 'Idagdag sa cart',
        'View details': 'Tingnan ang detalye',
        'View cart': 'Tingnan ang cart',
        'Continue shopping': 'Magpatuloy sa pamimili',
        'Your basket': 'Ang basket mo',
        'Your cart': 'Cart mo',
        'Your cart is empty': 'Walang laman ang cart mo',
        'Find something fresh from the farm and add it to your basket.': 'Pumili ng sariwang ani mula sa bukid at idagdag ito sa basket mo.',
        'Browse produce': 'Mamili ng gulay',
        'Produce': 'Produkto',
        'Price': 'Presyo',
        'Subtotal': 'Subtotal',
        'Update': 'I-update',
        'Remove': 'Alisin',
        'Checkout': 'Mag-checkout',
        'Edit cart': 'I-edit ang cart',
        'Order summary': 'Buod ng order',
        'Vegetable subtotal': 'Subtotal ng gulay',
        'Bulk purchase discount': 'Diskuwento sa maramihang bili',
        'Bulk discount': 'Diskuwento sa maramihang bili',
        'Delivery fee': 'Bayad sa delivery',
        'Final total': 'Kabuuang halaga',
        'Combined cart quantity:': 'Pinagsamang dami sa cart:',
        'Review cart': 'Suriin ang cart',
        'Delivery details': 'Detalye ng delivery',
        'Delivery address': 'Address ng delivery',
        'Recipient name': 'Pangalan ng tatanggap',
        'Contact number': 'Contact number',
        'House number and street': 'Numero ng bahay at kalye',
        'Barangay': 'Barangay',
        'City / Municipality': 'Lungsod / Munisipalidad',
        'Delivery instructions': 'Tagubilin sa delivery',
        'Payment method': 'Paraan ng bayad',
        'Cash on Delivery': 'Bayad pagdating (COD)',
        'Online payment (GCash/card)': 'Online na bayad (GCash/card)',
        'Place order': 'Ilagay ang order',
        'Your purchase, delivery, and payment history.': 'Kasaysayan ng iyong mga binili, delivery, at bayad.',
        'Order': 'Order',
        'Date': 'Petsa',
        'Items': 'Mga produkto',
        'Total': 'Kabuuan',
        'Payment': 'Bayad',
        'Delivery': 'Delivery',
        'Delivery status': 'Status ng delivery',
        'Payment status': 'Status ng bayad',
        'You have not placed any orders yet.': 'Wala ka pang naipapasang order.',
        'You have not placed an order yet.': 'Wala ka pang naipapasang order.',
        'Order details': 'Detalye ng order',
        'Print receipt': 'I-print ang resibo',
        'Back to orders': 'Bumalik sa mga order',
        'Placed': 'Naipasa',
        'Address': 'Address',
        'Unit price': 'Presyo bawat yunit',
        'Line subtotal': 'Subtotal ng item',
        'Payment history': 'Kasaysayan ng bayad',
        'No payment events have been recorded.': 'Wala pang naitatalang kaganapan sa bayad.',
        'Product details': 'Detalye ng produkto',
        'More about this produce': 'Dagdag na impormasyon tungkol sa produktong ito',
        'Product information': 'Impormasyon ng produkto',
        'Variety': 'Variety',
        'Unit': 'Yunit',
        'Stock': 'Stock',
        'Description': 'Paglalarawan',
        'Most recent harvest': 'Pinakahuling anihan',
        'No favorites saved yet': 'Wala ka pang naka-save na paborito',
        'Save produce from the shop or dashboard to find it quickly next time.': 'Mag-save ng produkto mula sa shop o dashboard para madali mo itong makita sa susunod.',
        'Explore available produce': 'Tingnan ang mga available na produkto',
        'Your recent orders': 'Mga kamakailang order mo',
        'Recent orders': 'Mga kamakailang order',
        'Quick links': 'Mabilisang link',
        'Track your orders': 'Subaybayan ang mga order mo',
        'Account role': 'Uri ng account',
        'Buyer account': 'Buyer account',
        'Welcome': 'Kumusta',
        'Actions': 'Mga aksyon',
        'confirmed': 'kumpirmado',
        'pending': 'nakabinbin',
        'preparing': 'inihahanda',
        'ready for delivery': 'handa na para ihatid',
        'out for delivery': 'nasa biyahe',
        'delivered': 'naideliver',
        'cancelled': 'kinansela',
        'pending payment': 'naghihintay ng bayad',
        'paid': 'bayad na',
        'failed': 'hindi nagtagumpay',
        'refunded': 'na-refund',
        'Please log in to continue.': 'Mag-log in upang magpatuloy.',
        'Choose a valid product and quantity.': 'Pumili ng wastong produkto at dami.',
        'That item is unavailable or the requested amount exceeds stock.': 'Hindi available ang produktong ito o sobra sa stock ang hinihinging dami.',
        'Cart updated.': 'Na-update ang cart.',
        'Item removed from cart.': 'Inalis ang produkto sa cart.',
        'Your order is confirmed. Pay cash when it is delivered.': 'Kumpirmado na ang order mo. Magbayad ng cash kapag naihatid na ito.',
        'Review payment or delivery progress for these orders.': 'Tingnan ang progreso ng bayad o delivery ng mga order na ito.',
        'Orders to follow up': 'Mga order na kailangang subaybayan',
        'View order': 'Tingnan ang order',
        'Buy again': 'Bilhin muli',
    }));
    const originalText = new WeakMap();
    const originalAttributes = new WeakMap();
    const toggle = document.querySelector('[data-buyer-language-toggle]');
    let language = 'en';

    try {
        language = localStorage.getItem(storageKey) === 'fil' ? 'fil' : 'en';
    } catch (error) {
        console.error('Buyer language preference could not be read.', error);
    }

    function translateTextNode(node) {
        if (!originalText.has(node)) {
            originalText.set(node, node.nodeValue);
        }
        const source = originalText.get(node);
        const phrase = source.trim();
        const translation = translations.get(phrase) || translations.get(phrase.toLowerCase());
        if (!translation) {
            return;
        }

        const renderedTranslation = /^[A-Z]/.test(phrase)
            ? translation.charAt(0).toUpperCase() + translation.slice(1)
            : translation;
        node.nodeValue = language === 'fil'
            ? source.replace(phrase, renderedTranslation)
            : source;
    }

    function translateElement(element) {
        element.childNodes.forEach((node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                translateTextNode(node);
            } else if (node instanceof Element) {
                translateElement(node);
            }
        });

        ['aria-label', 'placeholder', 'title'].forEach((attribute) => {
            if (!element.hasAttribute(attribute)) {
                return;
            }
            let values = originalAttributes.get(element);
            if (!values) {
                values = new Map();
                originalAttributes.set(element, values);
            }
            if (!values.has(attribute)) {
                values.set(attribute, element.getAttribute(attribute));
            }
            const source = values.get(attribute);
            element.setAttribute(attribute, language === 'fil'
                ? (translations.get(source) || source)
                : source);
        });
    }

    function applyLanguage() {
        document.documentElement.lang = language === 'fil' ? 'fil' : 'en';
        translateElement(document.body);
        if (toggle) {
            toggle.textContent = language === 'fil' ? 'English' : 'Filipino';
            toggle.setAttribute('aria-label', language === 'fil' ? 'Switch to English' : 'Switch to Filipino');
        }
    }

    if (toggle) {
        toggle.addEventListener('click', () => {
            language = language === 'en' ? 'fil' : 'en';
            try {
                localStorage.setItem(storageKey, language);
            } catch (error) {
                console.error('Buyer language preference could not be saved.', error);
            }
            applyLanguage();
        });
    }

    const contentObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    translateElement(node);
                } else if (node.nodeType === Node.TEXT_NODE) {
                    translateTextNode(node);
                }
            });
        });
    });
    contentObserver.observe(document.body, { childList: true, subtree: true });
    applyLanguage();
})();
