<?php

/**
 * Tourfecto - القالب الاحترافي لمواقع شركات الرحلات المولّدة (الصفحة الرئيسية وصفحة الرحلة)
 *
 * فاتح وواضح زي مواقع الحجز الكبيرة: صورة كبيرة فوق، شريط ثقة، كروت رحلات بصور، خطوات الحجز،
 * أسئلة شائعة، تواصل بخريطة، زرار واتساب عايم، وشريط حجز ثابت على الموبايل.
 * نصوص الواجهة هنا بـ 6 لغات (مش بنعتمد على قاموس الواجهة العربي عشان النسخ المترجمة).
 * الشعارات في شريط الثقة بتطلع من بيانات الشركة بس - مفيش وعود مش حقيقية.
 */
class TourSiteTheme
{
    private const T = [
        'en' => ['tours' => 'Tours', 'why' => 'Why us', 'reviews' => 'Reviews', 'contact' => 'Contact', 'book' => 'Book now', 'book_wa' => 'Book on WhatsApp', 'chat_wa' => 'Chat on WhatsApp',
            'view' => 'View tour', 'duration' => 'Duration', 'group' => 'Group size', 'price' => 'Price', 'our_tours' => 'Our tours', 'our_tours_sub' => 'Pick your trip, see exactly what is included and book directly with the local team.',
            'see_tours' => 'See our tours', 'why_t' => 'Why travellers book with us', 'direct_t' => 'Book direct with the local team', 'direct_d' => 'No middleman: you talk to the people who run your trip.',
            'wa_t' => 'Fast answers on WhatsApp', 'wa_d' => 'Ask anything before you book and get a quick reply.', 'clear_t' => 'Clear details before you pay', 'clear_d' => 'Every tour lists what is included and what is not.',
            'pickup_t' => 'Hotel pickup', 'pickup_d' => 'Pickup from your hotel is part of our tours.', 'how' => 'How booking works', 'how1' => 'Choose your tour', 'how1d' => 'Compare tours, duration and price.',
            'how2' => 'Send your date', 'how2d' => 'Book online or message us on WhatsApp.', 'how3' => 'Enjoy the trip', 'how3d' => 'We confirm your booking and meet you on the day.',
            'about' => 'About us', 'contact_t' => 'Get in touch', 'call' => 'Call us', 'email' => 'Email', 'address' => 'Address', 'highlights' => 'Highlights', 'itinerary' => 'Itinerary', 'day' => 'Day',
            'included' => 'What is included', 'not_included' => 'Not included', 'book_tour' => 'Book this tour', 'book_sub_form' => 'Choose your date and send your details - we confirm your booking.',
            'book_sub_wa' => 'Send us your date on WhatsApp and we confirm availability.', 'or' => 'or contact us directly', 'more' => 'You may also like', 'all' => 'All tours', 'home' => 'Home',
            'faq' => 'Questions before you book', 'built' => 'Website by Tourfecto', 'wa_home' => 'Hello, I would like to ask about your tours.', 'wa_tour' => 'Hello, I would like to book "%s". Please send me the details.',
            'n_tours' => '%d tours', 'enquiry' => 'Send an enquiry', 'map' => 'Map', 'menu' => 'Menu'],
        'ar' => ['tours' => 'الرحلات', 'why' => 'ليه إحنا', 'reviews' => 'التقييمات', 'contact' => 'تواصل معنا', 'book' => 'احجز الآن', 'book_wa' => 'احجز على واتساب', 'chat_wa' => 'كلّمنا على واتساب',
            'view' => 'تفاصيل الرحلة', 'duration' => 'المدة', 'group' => 'حجم المجموعة', 'price' => 'السعر', 'our_tours' => 'رحلاتنا', 'our_tours_sub' => 'اختار رحلتك، واعرف بالظبط إيه اللي شامل، واحجز مباشرة مع فريقنا.',
            'see_tours' => 'شوف رحلاتنا', 'why_t' => 'ليه المسافرين بيحجزوا معانا', 'direct_t' => 'حجز مباشر مع الفريق المحلي', 'direct_d' => 'من غير وسيط: بتكلّم الناس اللي بينظّموا رحلتك بنفسهم.',
            'wa_t' => 'رد سريع على واتساب', 'wa_d' => 'اسأل أي حاجة قبل ما تحجز وهترد عليك بسرعة.', 'clear_t' => 'كل التفاصيل واضحة قبل الدفع', 'clear_d' => 'كل رحلة مكتوب فيها إيه شامل وإيه مش شامل.',
            'pickup_t' => 'استقبال من الفندق', 'pickup_d' => 'التوصيل من فندقك جزء من رحلاتنا.', 'how' => 'الحجز بيتم إزاي', 'how1' => 'اختار رحلتك', 'how1d' => 'قارن بين الرحلات والمدة والسعر.',
            'how2' => 'ابعت التاريخ', 'how2d' => 'احجز أونلاين أو ابعتلنا على واتساب.', 'how3' => 'استمتع برحلتك', 'how3d' => 'بنأكد حجزك ونقابلك في الميعاد.',
            'about' => 'عننا', 'contact_t' => 'تواصل معنا', 'call' => 'اتصل بنا', 'email' => 'الإيميل', 'address' => 'العنوان', 'highlights' => 'أبرز ما في الرحلة', 'itinerary' => 'برنامج الرحلة', 'day' => 'اليوم',
            'included' => 'الرحلة شاملة', 'not_included' => 'مش شامل', 'book_tour' => 'احجز الرحلة دي', 'book_sub_form' => 'اختار التاريخ واكتب بياناتك وهنأكد حجزك.',
            'book_sub_wa' => 'ابعتلنا التاريخ على واتساب وهنأكدلك المكان.', 'or' => 'أو كلّمنا مباشرة', 'more' => 'رحلات تانية ممكن تعجبك', 'all' => 'كل الرحلات', 'home' => 'الرئيسية',
            'faq' => 'أسئلة قبل الحجز', 'built' => 'الموقع من Tourfecto', 'wa_home' => 'أهلًا، عايز أسأل عن رحلاتكم.', 'wa_tour' => 'أهلًا، عايز أحجز «%s» - ابعتلي التفاصيل من فضلك.',
            'n_tours' => '%d رحلة', 'enquiry' => 'ابعت استفسار', 'map' => 'الخريطة', 'menu' => 'القائمة'],
        'de' => ['tours' => 'Touren', 'why' => 'Warum wir', 'reviews' => 'Bewertungen', 'contact' => 'Kontakt', 'book' => 'Jetzt buchen', 'book_wa' => 'Per WhatsApp buchen', 'chat_wa' => 'WhatsApp-Chat',
            'view' => 'Zur Tour', 'duration' => 'Dauer', 'group' => 'Gruppengröße', 'price' => 'Preis', 'our_tours' => 'Unsere Touren', 'our_tours_sub' => 'Wählen Sie Ihre Tour, sehen Sie genau, was enthalten ist, und buchen Sie direkt beim lokalen Team.',
            'see_tours' => 'Touren ansehen', 'why_t' => 'Warum Reisende bei uns buchen', 'direct_t' => 'Direkt beim lokalen Team buchen', 'direct_d' => 'Kein Vermittler: Sie sprechen mit den Menschen, die Ihre Tour durchführen.',
            'wa_t' => 'Schnelle Antworten per WhatsApp', 'wa_d' => 'Fragen Sie vor der Buchung und erhalten Sie schnell eine Antwort.', 'clear_t' => 'Klare Details vor der Zahlung', 'clear_d' => 'Bei jeder Tour steht, was enthalten ist und was nicht.',
            'pickup_t' => 'Abholung vom Hotel', 'pickup_d' => 'Die Abholung von Ihrem Hotel gehört zu unseren Touren.', 'how' => 'So funktioniert die Buchung', 'how1' => 'Tour wählen', 'how1d' => 'Touren, Dauer und Preise vergleichen.',
            'how2' => 'Datum senden', 'how2d' => 'Online buchen oder per WhatsApp schreiben.', 'how3' => 'Tour genießen', 'how3d' => 'Wir bestätigen Ihre Buchung und treffen Sie am Tag.',
            'about' => 'Über uns', 'contact_t' => 'Kontakt', 'call' => 'Anrufen', 'email' => 'E-Mail', 'address' => 'Adresse', 'highlights' => 'Höhepunkte', 'itinerary' => 'Ablauf', 'day' => 'Tag',
            'included' => 'Inklusive', 'not_included' => 'Nicht inklusive', 'book_tour' => 'Diese Tour buchen', 'book_sub_form' => 'Datum wählen und Daten senden - wir bestätigen Ihre Buchung.',
            'book_sub_wa' => 'Senden Sie uns Ihr Datum per WhatsApp und wir bestätigen die Verfügbarkeit.', 'or' => 'oder direkt Kontakt aufnehmen', 'more' => 'Das könnte Ihnen auch gefallen', 'all' => 'Alle Touren', 'home' => 'Startseite',
            'faq' => 'Fragen vor der Buchung', 'built' => 'Website von Tourfecto', 'wa_home' => 'Hallo, ich habe eine Frage zu Ihren Touren.', 'wa_tour' => 'Hallo, ich möchte „%s“ buchen. Bitte senden Sie mir die Details.',
            'n_tours' => '%d Touren', 'enquiry' => 'Anfrage senden', 'map' => 'Karte', 'menu' => 'Menü'],
        'fr' => ['tours' => 'Excursions', 'why' => 'Pourquoi nous', 'reviews' => 'Avis', 'contact' => 'Contact', 'book' => 'Réserver', 'book_wa' => 'Réserver sur WhatsApp', 'chat_wa' => 'Discuter sur WhatsApp',
            'view' => 'Voir l’excursion', 'duration' => 'Durée', 'group' => 'Taille du groupe', 'price' => 'Prix', 'our_tours' => 'Nos excursions', 'our_tours_sub' => 'Choisissez votre excursion, voyez ce qui est inclus et réservez directement avec l’équipe locale.',
            'see_tours' => 'Voir nos excursions', 'why_t' => 'Pourquoi les voyageurs réservent chez nous', 'direct_t' => 'Réservation directe avec l’équipe locale', 'direct_d' => 'Sans intermédiaire : vous parlez à ceux qui organisent votre excursion.',
            'wa_t' => 'Réponses rapides sur WhatsApp', 'wa_d' => 'Posez vos questions avant de réserver et recevez une réponse rapide.', 'clear_t' => 'Des détails clairs avant de payer', 'clear_d' => 'Chaque excursion indique ce qui est inclus ou non.',
            'pickup_t' => 'Prise en charge à l’hôtel', 'pickup_d' => 'La prise en charge à votre hôtel fait partie de nos excursions.', 'how' => 'Comment réserver', 'how1' => 'Choisissez l’excursion', 'how1d' => 'Comparez les excursions, la durée et le prix.',
            'how2' => 'Envoyez votre date', 'how2d' => 'Réservez en ligne ou écrivez-nous sur WhatsApp.', 'how3' => 'Profitez du voyage', 'how3d' => 'Nous confirmons et vous retrouvons le jour J.',
            'about' => 'À propos', 'contact_t' => 'Nous contacter', 'call' => 'Appeler', 'email' => 'E-mail', 'address' => 'Adresse', 'highlights' => 'Points forts', 'itinerary' => 'Programme', 'day' => 'Jour',
            'included' => 'Inclus', 'not_included' => 'Non inclus', 'book_tour' => 'Réserver cette excursion', 'book_sub_form' => 'Choisissez votre date et envoyez vos informations - nous confirmons votre réservation.',
            'book_sub_wa' => 'Envoyez-nous votre date sur WhatsApp et nous confirmons la disponibilité.', 'or' => 'ou contactez-nous directement', 'more' => 'Vous aimerez aussi', 'all' => 'Toutes les excursions', 'home' => 'Accueil',
            'faq' => 'Questions avant de réserver', 'built' => 'Site par Tourfecto', 'wa_home' => 'Bonjour, j’ai une question sur vos excursions.', 'wa_tour' => 'Bonjour, je souhaite réserver « %s ». Merci de m’envoyer les détails.',
            'n_tours' => '%d excursions', 'enquiry' => 'Envoyer une demande', 'map' => 'Carte', 'menu' => 'Menu'],
        'it' => ['tours' => 'Escursioni', 'why' => 'Perché noi', 'reviews' => 'Recensioni', 'contact' => 'Contatti', 'book' => 'Prenota', 'book_wa' => 'Prenota su WhatsApp', 'chat_wa' => 'Chatta su WhatsApp',
            'view' => 'Vedi escursione', 'duration' => 'Durata', 'group' => 'Gruppo', 'price' => 'Prezzo', 'our_tours' => 'Le nostre escursioni', 'our_tours_sub' => 'Scegli la tua escursione, guarda cosa è incluso e prenota direttamente con il team locale.',
            'see_tours' => 'Vedi le escursioni', 'why_t' => 'Perché i viaggiatori prenotano con noi', 'direct_t' => 'Prenoti direttamente con il team locale', 'direct_d' => 'Nessun intermediario: parli con chi organizza la tua escursione.',
            'wa_t' => 'Risposte rapide su WhatsApp', 'wa_d' => 'Chiedi qualsiasi cosa prima di prenotare e ricevi una risposta veloce.', 'clear_t' => 'Dettagli chiari prima di pagare', 'clear_d' => 'Ogni escursione indica cosa è incluso e cosa no.',
            'pickup_t' => 'Prelievo in hotel', 'pickup_d' => 'Il prelievo dal tuo hotel fa parte delle nostre escursioni.', 'how' => 'Come prenotare', 'how1' => 'Scegli l’escursione', 'how1d' => 'Confronta escursioni, durata e prezzo.',
            'how2' => 'Invia la data', 'how2d' => 'Prenota online o scrivici su WhatsApp.', 'how3' => 'Goditi il viaggio', 'how3d' => 'Confermiamo la prenotazione e ti incontriamo il giorno stabilito.',
            'about' => 'Chi siamo', 'contact_t' => 'Contattaci', 'call' => 'Chiama', 'email' => 'E-mail', 'address' => 'Indirizzo', 'highlights' => 'Punti salienti', 'itinerary' => 'Programma', 'day' => 'Giorno',
            'included' => 'Incluso', 'not_included' => 'Non incluso', 'book_tour' => 'Prenota questa escursione', 'book_sub_form' => 'Scegli la data e invia i tuoi dati - confermiamo la prenotazione.',
            'book_sub_wa' => 'Inviaci la data su WhatsApp e confermiamo la disponibilità.', 'or' => 'o contattaci direttamente', 'more' => 'Potrebbe piacerti anche', 'all' => 'Tutte le escursioni', 'home' => 'Home',
            'faq' => 'Domande prima di prenotare', 'built' => 'Sito di Tourfecto', 'wa_home' => 'Ciao, vorrei informazioni sulle vostre escursioni.', 'wa_tour' => 'Ciao, vorrei prenotare "%s". Potete inviarmi i dettagli?',
            'n_tours' => '%d escursioni', 'enquiry' => 'Invia una richiesta', 'map' => 'Mappa', 'menu' => 'Menu'],
        'es' => ['tours' => 'Tours', 'why' => 'Por qué nosotros', 'reviews' => 'Opiniones', 'contact' => 'Contacto', 'book' => 'Reservar', 'book_wa' => 'Reservar por WhatsApp', 'chat_wa' => 'Chatear por WhatsApp',
            'view' => 'Ver tour', 'duration' => 'Duración', 'group' => 'Grupo', 'price' => 'Precio', 'our_tours' => 'Nuestros tours', 'our_tours_sub' => 'Elige tu tour, mira exactamente qué incluye y reserva directamente con el equipo local.',
            'see_tours' => 'Ver tours', 'why_t' => 'Por qué los viajeros reservan con nosotros', 'direct_t' => 'Reserva directa con el equipo local', 'direct_d' => 'Sin intermediarios: hablas con quienes organizan tu tour.',
            'wa_t' => 'Respuestas rápidas por WhatsApp', 'wa_d' => 'Pregunta lo que quieras antes de reservar y recibe respuesta rápida.', 'clear_t' => 'Detalles claros antes de pagar', 'clear_d' => 'Cada tour indica qué incluye y qué no.',
            'pickup_t' => 'Recogida en el hotel', 'pickup_d' => 'La recogida en tu hotel forma parte de nuestros tours.', 'how' => 'Cómo reservar', 'how1' => 'Elige tu tour', 'how1d' => 'Compara tours, duración y precio.',
            'how2' => 'Envía tu fecha', 'how2d' => 'Reserva online o escríbenos por WhatsApp.', 'how3' => 'Disfruta del viaje', 'how3d' => 'Confirmamos tu reserva y te recogemos el día del tour.',
            'about' => 'Sobre nosotros', 'contact_t' => 'Contáctanos', 'call' => 'Llamar', 'email' => 'Correo', 'address' => 'Dirección', 'highlights' => 'Lo más destacado', 'itinerary' => 'Itinerario', 'day' => 'Día',
            'included' => 'Incluye', 'not_included' => 'No incluye', 'book_tour' => 'Reservar este tour', 'book_sub_form' => 'Elige la fecha y envía tus datos - confirmamos tu reserva.',
            'book_sub_wa' => 'Envíanos tu fecha por WhatsApp y confirmamos la disponibilidad.', 'or' => 'o contáctanos directamente', 'more' => 'También te puede gustar', 'all' => 'Todos los tours', 'home' => 'Inicio',
            'faq' => 'Preguntas antes de reservar', 'built' => 'Web de Tourfecto', 'wa_home' => 'Hola, quiero preguntar por sus tours.', 'wa_tour' => 'Hola, quiero reservar "%s". ¿Me envían los detalles?',
            'n_tours' => '%d tours', 'enquiry' => 'Enviar consulta', 'map' => 'Mapa', 'menu' => 'Menú'],
    ];


    /** نصوص الصفحات الإضافية (كل الصفحات + الفلاتر + الفوتر) */
    private const T2 = [
        'en' => ['bank_t' => 'Bank transfer', 'wallet_t' => 'Mobile wallet', 'pg_tours' => 'All tours', 'pg_about' => 'About us', 'pg_contact' => 'Contact', 'pg_faq' => 'FAQ', 'pg_reviews' => 'Reviews', 'pg_gallery' => 'Gallery', 'pg_terms' => 'Terms & conditions', 'pg_privacy' => 'Privacy policy', 'pg_cancel' => 'Cancellation policy',
            'search' => 'Search tours…', 'all_d' => 'All', 'half' => 'Half day', 'full' => 'Full day', 'multi' => 'Multi-day', 'sort' => 'Sort', 'sort_def' => 'Recommended', 'sort_low' => 'Price: low to high', 'sort_high' => 'Price: high to low', 'no_results' => 'No tours match your search.',
            'cta_t' => 'Ready for your next adventure?', 'cta_d' => 'Tell us your dates and we will plan everything for you.', 'top' => 'Popular tours', 'see_all' => 'See all tours', 'quick' => 'Explore', 'policies' => 'Policies', 'pay' => 'Ways to pay', 'follow' => 'Follow us',
            'read_more' => 'Read our story', 'faq_more' => 'All questions', 'gallery_sub' => 'Moments from our tours.', 'reviews_sub' => 'What travellers say about us.', 'contact_sub' => 'We usually reply on WhatsApp within minutes during working hours.', 'about_sub' => 'Who we are and how we run our tours.', 'faq_sub' => 'Everything you want to know before you book.',
            'how_book_q' => 'How do I book a tour?', 'how_book_a' => 'Open the tour you like and book online, or send us your date on WhatsApp. We confirm your booking by message.', 'pay_q' => 'How can I pay?', 'pay_a' => 'You can pay with: %s.', 'cancel_q' => 'What is your cancellation policy?', 'cancel_a' => 'Free cancellation up to %d hours before the tour. After that, %s%% of the price is refunded.', 'cancel_contact' => 'Please contact us about cancellation for your tour - the conditions depend on the tour and date.',
            'updated' => 'Last updated', 'tours_n' => 'Tours', 'langs' => 'Languages', 'from' => 'from'],
        'ar' => ['bank_t' => 'تحويل بنكي', 'wallet_t' => 'محفظة موبايل', 'pg_tours' => 'كل الرحلات', 'pg_about' => 'من نحن', 'pg_contact' => 'تواصل معنا', 'pg_faq' => 'الأسئلة الشائعة', 'pg_reviews' => 'التقييمات', 'pg_gallery' => 'معرض الصور', 'pg_terms' => 'الشروط والأحكام', 'pg_privacy' => 'سياسة الخصوصية', 'pg_cancel' => 'سياسة الإلغاء',
            'search' => 'دوّر على رحلة…', 'all_d' => 'الكل', 'half' => 'نص يوم', 'full' => 'يوم كامل', 'multi' => 'أكتر من يوم', 'sort' => 'الترتيب', 'sort_def' => 'المقترح', 'sort_low' => 'الأرخص الأول', 'sort_high' => 'الأغلى الأول', 'no_results' => 'مفيش رحلات مطابقة للبحث.',
            'cta_t' => 'جاهز لرحلتك الجاية؟', 'cta_d' => 'قولّنا مواعيدك وإحنا نرتّبلك كل حاجة.', 'top' => 'أشهر الرحلات', 'see_all' => 'كل الرحلات', 'quick' => 'استكشف', 'policies' => 'السياسات', 'pay' => 'طرق الدفع', 'follow' => 'تابعنا',
            'read_more' => 'اعرف قصتنا', 'faq_more' => 'كل الأسئلة', 'gallery_sub' => 'لقطات من رحلاتنا.', 'reviews_sub' => 'المسافرين بيقولوا إيه عننا.', 'contact_sub' => 'بنرد على واتساب في دقايق في مواعيد الشغل.', 'about_sub' => 'إحنا مين وبننظّم رحلاتنا إزاي.', 'faq_sub' => 'كل اللي محتاج تعرفه قبل ما تحجز.',
            'how_book_q' => 'أحجز رحلة إزاي؟', 'how_book_a' => 'افتح الرحلة اللي عاجباك واحجز أونلاين، أو ابعتلنا التاريخ على واتساب، وهنأكد حجزك برسالة.', 'pay_q' => 'أدفع إزاي؟', 'pay_a' => 'تقدر تدفع عن طريق: %s.', 'cancel_q' => 'سياسة الإلغاء إيه؟', 'cancel_a' => 'الإلغاء مجاني لحد %d ساعة قبل الرحلة. بعد كده بيترد %s%% من السعر.', 'cancel_contact' => 'كلّمنا بخصوص الإلغاء لرحلتك - الشروط بتختلف حسب الرحلة والتاريخ.',
            'updated' => 'آخر تحديث', 'tours_n' => 'رحلة', 'langs' => 'لغات', 'from' => 'من'],
        'de' => ['bank_t' => 'Banküberweisung', 'wallet_t' => 'Mobile Wallet', 'pg_tours' => 'Alle Touren', 'pg_about' => 'Über uns', 'pg_contact' => 'Kontakt', 'pg_faq' => 'FAQ', 'pg_reviews' => 'Bewertungen', 'pg_gallery' => 'Galerie', 'pg_terms' => 'AGB', 'pg_privacy' => 'Datenschutz', 'pg_cancel' => 'Stornobedingungen',
            'search' => 'Touren suchen…', 'all_d' => 'Alle', 'half' => 'Halbtags', 'full' => 'Ganztags', 'multi' => 'Mehrtägig', 'sort' => 'Sortieren', 'sort_def' => 'Empfohlen', 'sort_low' => 'Preis aufsteigend', 'sort_high' => 'Preis absteigend', 'no_results' => 'Keine passenden Touren.',
            'cta_t' => 'Bereit für Ihr nächstes Abenteuer?', 'cta_d' => 'Nennen Sie uns Ihre Daten - wir planen alles für Sie.', 'top' => 'Beliebte Touren', 'see_all' => 'Alle Touren ansehen', 'quick' => 'Entdecken', 'policies' => 'Richtlinien', 'pay' => 'Zahlungsarten', 'follow' => 'Folgen Sie uns',
            'read_more' => 'Unsere Geschichte', 'faq_more' => 'Alle Fragen', 'gallery_sub' => 'Momente unserer Touren.', 'reviews_sub' => 'Was Reisende über uns sagen.', 'contact_sub' => 'Wir antworten zu Geschäftszeiten meist innerhalb weniger Minuten per WhatsApp.', 'about_sub' => 'Wer wir sind und wie wir unsere Touren gestalten.', 'faq_sub' => 'Alles, was Sie vor der Buchung wissen möchten.',
            'how_book_q' => 'Wie buche ich eine Tour?', 'how_book_a' => 'Öffnen Sie die Tour und buchen Sie online oder senden Sie uns Ihr Datum per WhatsApp. Wir bestätigen per Nachricht.', 'pay_q' => 'Wie kann ich bezahlen?', 'pay_a' => 'Sie können bezahlen mit: %s.', 'cancel_q' => 'Wie sind die Stornobedingungen?', 'cancel_a' => 'Kostenlose Stornierung bis %d Stunden vor der Tour. Danach werden %s%% des Preises erstattet.', 'cancel_contact' => 'Bitte kontaktieren Sie uns zur Stornierung - die Bedingungen hängen von Tour und Datum ab.',
            'updated' => 'Zuletzt aktualisiert', 'tours_n' => 'Touren', 'langs' => 'Sprachen', 'from' => 'ab'],
        'fr' => ['bank_t' => 'Virement bancaire', 'wallet_t' => 'Portefeuille mobile', 'pg_tours' => 'Toutes les excursions', 'pg_about' => 'À propos', 'pg_contact' => 'Contact', 'pg_faq' => 'FAQ', 'pg_reviews' => 'Avis', 'pg_gallery' => 'Galerie', 'pg_terms' => 'Conditions générales', 'pg_privacy' => 'Confidentialité', 'pg_cancel' => 'Conditions d’annulation',
            'search' => 'Rechercher…', 'all_d' => 'Tout', 'half' => 'Demi-journée', 'full' => 'Journée', 'multi' => 'Plusieurs jours', 'sort' => 'Trier', 'sort_def' => 'Recommandé', 'sort_low' => 'Prix croissant', 'sort_high' => 'Prix décroissant', 'no_results' => 'Aucune excursion ne correspond.',
            'cta_t' => 'Prêt pour votre prochaine aventure ?', 'cta_d' => 'Donnez-nous vos dates, nous organisons tout.', 'top' => 'Excursions populaires', 'see_all' => 'Voir toutes les excursions', 'quick' => 'Découvrir', 'policies' => 'Conditions', 'pay' => 'Moyens de paiement', 'follow' => 'Suivez-nous',
            'read_more' => 'Notre histoire', 'faq_more' => 'Toutes les questions', 'gallery_sub' => 'Des moments de nos excursions.', 'reviews_sub' => 'Ce que disent les voyageurs.', 'contact_sub' => 'Nous répondons généralement sur WhatsApp en quelques minutes aux heures d’ouverture.', 'about_sub' => 'Qui nous sommes et comment nous organisons nos excursions.', 'faq_sub' => 'Tout ce qu’il faut savoir avant de réserver.',
            'how_book_q' => 'Comment réserver ?', 'how_book_a' => 'Ouvrez l’excursion et réservez en ligne, ou envoyez-nous votre date sur WhatsApp. Nous confirmons par message.', 'pay_q' => 'Comment payer ?', 'pay_a' => 'Vous pouvez payer par : %s.', 'cancel_q' => 'Quelles sont les conditions d’annulation ?', 'cancel_a' => 'Annulation gratuite jusqu’à %d heures avant l’excursion. Ensuite, %s%% du prix est remboursé.', 'cancel_contact' => 'Contactez-nous pour l’annulation - les conditions dépendent de l’excursion et de la date.',
            'updated' => 'Mis à jour', 'tours_n' => 'Excursions', 'langs' => 'Langues', 'from' => 'dès'],
        'it' => ['bank_t' => 'Bonifico bancario', 'wallet_t' => 'Portafoglio mobile', 'pg_tours' => 'Tutte le escursioni', 'pg_about' => 'Chi siamo', 'pg_contact' => 'Contatti', 'pg_faq' => 'FAQ', 'pg_reviews' => 'Recensioni', 'pg_gallery' => 'Galleria', 'pg_terms' => 'Termini e condizioni', 'pg_privacy' => 'Privacy', 'pg_cancel' => 'Cancellazione',
            'search' => 'Cerca escursioni…', 'all_d' => 'Tutte', 'half' => 'Mezza giornata', 'full' => 'Giornata intera', 'multi' => 'Più giorni', 'sort' => 'Ordina', 'sort_def' => 'Consigliati', 'sort_low' => 'Prezzo crescente', 'sort_high' => 'Prezzo decrescente', 'no_results' => 'Nessuna escursione trovata.',
            'cta_t' => 'Pronto per la prossima avventura?', 'cta_d' => 'Dicci le tue date e organizziamo tutto noi.', 'top' => 'Escursioni più richieste', 'see_all' => 'Vedi tutte', 'quick' => 'Scopri', 'policies' => 'Condizioni', 'pay' => 'Pagamenti', 'follow' => 'Seguici',
            'read_more' => 'La nostra storia', 'faq_more' => 'Tutte le domande', 'gallery_sub' => 'Momenti dalle nostre escursioni.', 'reviews_sub' => 'Cosa dicono i viaggiatori.', 'contact_sub' => 'Di solito rispondiamo su WhatsApp in pochi minuti in orario di lavoro.', 'about_sub' => 'Chi siamo e come organizziamo le escursioni.', 'faq_sub' => 'Tutto quello che serve sapere prima di prenotare.',
            'how_book_q' => 'Come prenoto?', 'how_book_a' => 'Apri l’escursione e prenota online, oppure inviaci la data su WhatsApp. Confermiamo con un messaggio.', 'pay_q' => 'Come posso pagare?', 'pay_a' => 'Puoi pagare con: %s.', 'cancel_q' => 'Qual è la politica di cancellazione?', 'cancel_a' => 'Cancellazione gratuita fino a %d ore prima. Dopo, viene rimborsato il %s%% del prezzo.', 'cancel_contact' => 'Contattaci per la cancellazione - le condizioni dipendono da escursione e data.',
            'updated' => 'Aggiornato', 'tours_n' => 'Escursioni', 'langs' => 'Lingue', 'from' => 'da'],
        'es' => ['bank_t' => 'Transferencia bancaria', 'wallet_t' => 'Monedero móvil', 'pg_tours' => 'Todos los tours', 'pg_about' => 'Sobre nosotros', 'pg_contact' => 'Contacto', 'pg_faq' => 'Preguntas frecuentes', 'pg_reviews' => 'Opiniones', 'pg_gallery' => 'Galería', 'pg_terms' => 'Términos y condiciones', 'pg_privacy' => 'Privacidad', 'pg_cancel' => 'Cancelación',
            'search' => 'Buscar tours…', 'all_d' => 'Todos', 'half' => 'Medio día', 'full' => 'Día completo', 'multi' => 'Varios días', 'sort' => 'Ordenar', 'sort_def' => 'Recomendados', 'sort_low' => 'Precio: menor a mayor', 'sort_high' => 'Precio: mayor a menor', 'no_results' => 'No hay tours que coincidan.',
            'cta_t' => '¿Listo para tu próxima aventura?', 'cta_d' => 'Dinos tus fechas y lo organizamos todo.', 'top' => 'Tours populares', 'see_all' => 'Ver todos los tours', 'quick' => 'Explorar', 'policies' => 'Políticas', 'pay' => 'Formas de pago', 'follow' => 'Síguenos',
            'read_more' => 'Nuestra historia', 'faq_more' => 'Todas las preguntas', 'gallery_sub' => 'Momentos de nuestros tours.', 'reviews_sub' => 'Lo que dicen los viajeros.', 'contact_sub' => 'Solemos responder por WhatsApp en minutos en horario laboral.', 'about_sub' => 'Quiénes somos y cómo organizamos los tours.', 'faq_sub' => 'Todo lo que quieres saber antes de reservar.',
            'how_book_q' => '¿Cómo reservo?', 'how_book_a' => 'Abre el tour y reserva online, o envíanos tu fecha por WhatsApp. Confirmamos por mensaje.', 'pay_q' => '¿Cómo puedo pagar?', 'pay_a' => 'Puedes pagar con: %s.', 'cancel_q' => '¿Cuál es la política de cancelación?', 'cancel_a' => 'Cancelación gratuita hasta %d horas antes del tour. Después, se reembolsa el %s%% del precio.', 'cancel_contact' => 'Contáctanos sobre la cancelación - las condiciones dependen del tour y la fecha.',
            'updated' => 'Actualizado', 'tours_n' => 'Tours', 'langs' => 'Idiomas', 'from' => 'desde'],
    ];

    /**
     * 6 أشكال مختلفة تمامًا (خطوط، ألوان، شكل الهيرو والكروت). كل شركة بيتختارلها شكل يناسب رحلاتها،
     * والشركات اللي رحلاتها شبه بعض بتاخد أشكال مختلفة (حسب اسم الموقع) - فمفيش موقعين شبه بعض.
     */
    public const STYLES = [
        'classic' => ['fonts' => 'Plus+Jakarta+Sans:wght@400;600;700;800', 'ar' => 'IBM+Plex+Sans+Arabic:wght@400;600;700', 'h' => "'Plus Jakarta Sans'", 'b' => "'Plus Jakarta Sans'", 'arh' => "'IBM Plex Sans Arabic'", 'arb' => "'IBM Plex Sans Arabic'"],
        'luxe' => ['fonts' => 'Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;600;700', 'ar' => 'Amiri:wght@400;700&family=Readex+Pro:wght@300;400;600', 'h' => "'Cormorant Garamond'", 'b' => "'Manrope'", 'arh' => "'Amiri'", 'arb' => "'Readex Pro'"],
        'adventure' => ['fonts' => 'Archivo:wght@400;600;800;900', 'ar' => 'Cairo:wght@400;600;800;900', 'h' => "'Archivo'", 'b' => "'Archivo'", 'arh' => "'Cairo'", 'arb' => "'Cairo'"],
        'coastal' => ['fonts' => 'Nunito:wght@400;600;700;800', 'ar' => 'Tajawal:wght@400;500;700;800', 'h' => "'Nunito'", 'b' => "'Nunito'", 'arh' => "'Tajawal'", 'arb' => "'Tajawal'"],
        'heritage' => ['fonts' => 'Playfair+Display:wght@500;600;700&family=Source+Sans+3:wght@400;600;700', 'ar' => 'El+Messiri:wght@500;600;700&family=Noto+Kufi+Arabic:wght@400;600', 'h' => "'Playfair Display'", 'b' => "'Source Sans 3'", 'arh' => "'El Messiri'", 'arb' => "'Noto Kufi Arabic'"],
        'minimal' => ['fonts' => 'DM+Sans:wght@400;500;700&family=DM+Serif+Display', 'ar' => 'Readex+Pro:wght@300;400;600;700', 'h' => "'DM Serif Display'", 'b' => "'DM Sans'", 'arh' => "'Readex Pro'", 'arb' => "'Readex Pro'"],
    ];

    public static function pickStyle(array $c, string $slug): string
    {
        $s = (string) ($c['style'] ?? '');
        if (isset(self::STYLES[$s])) {
            return $s;
        }
        $txt = mb_strtolower(($c['business_name'] ?? '') . ' ' . ($c['tagline'] ?? '') . ' ' . implode(' ', array_map(fn ($t) => (string) ($t['name'] ?? ''), (array) ($c['tours'] ?? []))));
        $fit = [];
        if (preg_match('/luxury|private|vip|premium|yacht|فاخر|خاص|يخت/u', $txt)) $fit[] = 'luxe';
        if (preg_match('/safari|quad|buggy|desert|jeep|adventure|hiking|trek|سفاري|صحرا|مغامرة/u', $txt)) $fit[] = 'adventure';
        if (preg_match('/sea|snorkel|diving|island|boat|beach|reef|dolphin|cruise|بحر|غطس|جزيرة|مركب|شاطئ/u', $txt)) $fit[] = 'coastal';
        if (preg_match('/luxor|pyramid|temple|museum|history|ancient|heritage|cairo|aswan|أقصر|هرم|معبد|تاريخ|متحف/u', $txt)) $fit[] = 'heritage';
        $fit = array_values(array_unique(array_merge($fit, ['classic', 'minimal'])));
        return $fit[crc32($slug) % min(3, count($fit))];
    }

    private string $style = 'classic';

    public function setStyle(string $style): void
    {
        $this->style = isset(self::STYLES[$style]) ? $style : 'classic';
    }

    public function style(): string
    {
        return $this->style;
    }

    /** الخطوط + الـ CSS + متغيرات الشكل */
    public function headStyle(): string
    {
        $st = self::STYLES[$this->style];
        $ar = $this->lang === 'ar';
        $url = 'https://fonts.googleapis.com/css2?family=' . ($ar ? $st['ar'] : $st['fonts']) . '&display=swap';
        $h = $ar ? $st['arh'] : $st['h'];
        $b = $ar ? $st['arb'] : $st['b'];
        return '<link rel="stylesheet" href="' . self::e($url) . '">' . $this->head()
            . '<style>body.wsx{--fh:' . $h . ',system-ui,sans-serif;--fb:' . $b . ',system-ui,sans-serif}</style>';
    }

    private string $lang;
    private string $dir;

    public function __construct(string $lang, string $dir)
    {
        $l = strtolower(substr($lang, 0, 2));
        $this->lang = isset(self::T[$l]) ? $l : 'en';
        $this->dir = $dir;
    }

    public function t(string $key, ...$args): string
    {
        $s = self::T[$this->lang][$key] ?? self::T2[$this->lang][$key] ?? self::T['en'][$key] ?? self::T2['en'][$key] ?? $key;
        return $args ? sprintf($s, ...$args) : $s;
    }

    /** نفس t بس متأمّن للـ HTML */
    public function h(string $key, ...$args): string
    {
        return htmlspecialchars($this->t($key, ...$args), ENT_QUOTES, 'UTF-8');
    }

    public static function e($v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }

    public static function waNumber(array $contact): string
    {
        return preg_replace('/[^0-9]/', '', (string) ($contact['whatsapp'] ?? ($contact['phone'] ?? '')));
    }

    public function waLink(string $number, string $text): string
    {
        return $number !== '' ? 'https://wa.me/' . $number . '?text=' . rawurlencode($text) : '';
    }

    /** في رحلة فيها استقبال من الفندق؟ (عشان شريط الثقة مايوعدش بحاجة مش موجودة) */
    public static function hasPickup(array $tours): bool
    {
        foreach ($tours as $t) {
            $txt = mb_strtolower(implode(' ', array_merge((array) ($t['includes'] ?? []), (array) ($t['highlights'] ?? []))));
            if (preg_match('/pick ?-?up|transfer|hotel|abholung|prise en charge|prelievo|recogida|استقبال|توصيل|من الفندق|انتقالات/u', $txt)) {
                return true;
            }
        }
        return false;
    }

    /** رسم بسيط مكان الصورة على حسب نوع الرحلة (معبد / صحرا / بحر / مدينة) - بدل إيموجي */
    public static function art(string $name, int $i = 0): string
    {
        $n = mb_strtolower($name);
        $kind = preg_match('/luxor|karnak|pyramid|giza|temple|valley|aswan|abu simbel|cairo|museum|أقصر|هرم|معبد|أسوان|قاهرة|متحف/u', $n) ? 'temple'
            : (preg_match('/desert|safari|quad|buggy|jeep|camel|bedouin|dune|صحرا|سفاري|جمل|بدو/u', $n) ? 'desert'
            : (preg_match('/sea|snorkel|diving|island|boat|cruise|reef|dolphin|beach|yacht|بحر|غطس|سنوركل|جزيرة|مركب|يخت|دولفين|شاطئ/u', $n) ? 'sea' : 'city'));
        $palettes = ['temple' => ['#F6D9A5', '#E8A95A', '#B9742F'], 'desert' => ['#FBE3C0', '#F0B676', '#C9773A'], 'sea' => ['#BDE7F2', '#5BB7D6', '#1F7EA6'], 'city' => ['#DCE3F5', '#93A6DA', '#4E64A8']];
        [$a, $b, $c] = $palettes[$kind];
        $shape = [
            'temple' => '<path d="M60 150 L120 70 L180 150 Z" fill="' . $c . '" opacity=".85"/><path d="M150 150 L195 95 L240 150 Z" fill="' . $c . '" opacity=".6"/><rect x="0" y="150" width="320" height="50" fill="' . $b . '"/>',
            'desert' => '<path d="M0 150 C60 120 110 125 160 145 C210 165 260 130 320 140 L320 200 L0 200 Z" fill="' . $b . '"/><path d="M0 170 C80 150 140 160 200 175 C250 185 290 170 320 165 L320 200 L0 200 Z" fill="' . $c . '"/>',
            'sea' => '<path d="M0 140 C40 130 80 150 120 140 C160 130 200 150 240 140 C280 130 300 145 320 140 L320 200 L0 200 Z" fill="' . $b . '"/><path d="M0 165 C40 155 80 175 120 165 C160 155 200 175 240 165 C280 155 300 170 320 165 L320 200 L0 200 Z" fill="' . $c . '"/><path d="M200 120 L215 92 L230 120 Z" fill="#fff" opacity=".9"/>',
            'city' => '<rect x="40" y="105" width="34" height="55" fill="' . $c . '" opacity=".8"/><rect x="82" y="80" width="40" height="80" fill="' . $c . '"/><rect x="130" y="95" width="30" height="65" fill="' . $c . '" opacity=".7"/><rect x="0" y="158" width="320" height="42" fill="' . $b . '"/>',
        ][$kind];
        return '<svg class="wsx-art" viewBox="0 0 320 200" preserveAspectRatio="xMidYMid slice" role="img" aria-label="' . self::e($name) . '"><rect width="320" height="200" fill="' . $a . '"/><circle cx="' . (250 - ($i % 3) * 60) . '" cy="55" r="22" fill="#fff" opacity=".85"/>' . $shape . '</svg>';
    }

    public function head(): string
    {
        $v = function_exists('asset_v') ? asset_v('/assets/css/tour-site.css') : '/assets/css/tour-site.css';
        return '<link rel="stylesheet" href="' . self::e($v) . '">';
    }

    public function nav(string $home, string $name, string $logo, array $links, string $cta, string $ctaHref, string $current = ''): string
    {
        $brand = $logo !== '' ? '<img src="' . self::e($logo) . '" alt="" class="wsx-logo-img">' : '<span class="wsx-logo-mark" aria-hidden="true">' . self::e(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
        $l = '';
        foreach ($links as [$href, $label]) {
            $l .= '<a href="' . self::e($href) . '"' . ($current !== '' && $href === $current ? ' aria-current="page"' : '') . '>' . self::e($label) . '</a>';
        }
        $ext = str_starts_with($ctaHref, 'http') ? ' target="_blank" rel="noopener"' : '';
        return '<header class="wsx-nav"><div class="wsx-wrap wsx-nav-in"><a class="wsx-brand" href="' . self::e($home) . '">' . $brand . '<span>' . self::e($name) . '</span></a>'
            . '<nav class="wsx-links" aria-label="' . $this->h('menu') . '">' . $l . '</nav>'
            . ($ctaHref !== '' ? '<a class="wsx-btn wsx-btn-sm wsx-nav-cta" href="' . self::e($ctaHref) . '"' . $ext . '>' . self::e($cta) . '</a>' : '')
            . '<details class="wsx-burger"><summary aria-label="' . $this->h('menu') . '"><span></span><span></span><span></span></summary><div class="wsx-drawer">' . $l
            . ($ctaHref !== '' ? '<a class="wsx-btn wsx-btn-block" href="' . self::e($ctaHref) . '"' . $ext . '>' . self::e($cta) . '</a>' : '') . '</div></details>'
            . '</div></header>';
    }

    /** بانر أول الصفحات الداخلية */
    public function banner(string $title, string $sub, string $img = ''): string
    {
        return '<section class="wsx-banner">' . ($img !== '' ? '<div class="wsx-hero-bg" style="background-image:url(\'' . self::e($img) . '\')"></div>' : '<div class="wsx-hero-bg is-art"></div>')
            . '<div class="wsx-wrap wsx-banner-in"><h1>' . self::e($title) . '</h1>' . ($sub !== '' ? '<p>' . self::e($sub) . '</p>' : '') . '</div></section>';
    }

    /**
     * @param array $cols [['title', [[href,label],...]], ...]
     */
    public function footerFull(string $name, string $about, array $contact, array $cols, array $pay, string $home): string
    {
        $c = '';
        foreach ($cols as [$title, $links]) {
            if (!$links) {
                continue;
            }
            $c .= '<div><b class="wsx-fh">' . self::e($title) . '</b><ul>';
            foreach ($links as [$href, $label]) {
                $c .= '<li><a href="' . self::e($href) . '">' . self::e($label) . '</a></li>';
            }
            $c .= '</ul></div>';
        }
        $ct = '';
        foreach ([['💬', 'whatsapp'], ['📞', 'phone'], ['✉️', 'email'], ['📍', 'address']] as [$ic, $k]) {
            if (!empty($contact[$k])) {
                $ct .= '<li><span aria-hidden="true">' . $ic . '</span> <bdi>' . self::e($contact[$k]) . '</bdi></li>';
            }
        }
        $payHtml = $pay ? '<div class="wsx-pay"><b class="wsx-fh">' . $this->h('pay') . '</b><div>' . implode('', array_map(fn ($p) => '<span>' . self::e($p) . '</span>', $pay)) . '</div></div>' : '';
        return '<footer class="wsx-footer"><div class="wsx-wrap"><div class="wsx-fgrid"><div class="wsx-fabout"><a class="wsx-brand wsx-brand-inv" href="' . self::e($home) . '"><span>' . self::e($name) . '</span></a>'
            . ($about !== '' ? '<p>' . self::e(mb_strimwidth($about, 0, 220, '…')) . '</p>' : '') . ($ct !== '' ? '<ul class="wsx-fct">' . $ct . '</ul>' : '') . '</div>' . $c . '</div>'
            . $payHtml
            . '<div class="wsx-foot-b">© ' . date('Y') . ' ' . self::e($name) . ' · <a href="https://tourfecto.pro" rel="noopener">' . $this->h('built') . '</a></div></div></footer>';
    }

    public function floatingWa(string $href): string
    {
        if ($href === '') {
            return '';
        }
        return '<a class="wsx-wa-float" href="' . self::e($href) . '" target="_blank" rel="noopener" aria-label="' . $this->h('chat_wa') . '"><svg viewBox="0 0 32 32" aria-hidden="true"><path fill="currentColor" d="M16 3a13 13 0 0 0-11.2 19.6L3 29l6.6-1.7A13 13 0 1 0 16 3zm0 23.6c-2 0-4-.6-5.7-1.6l-.4-.2-3.9 1 1-3.8-.3-.4A10.6 10.6 0 1 1 16 26.6zm5.8-7.9c-.3-.2-1.9-.9-2.2-1-.3-.1-.5-.2-.7.2l-1 1.2c-.2.2-.4.2-.7.1a8.7 8.7 0 0 1-4.3-3.8c-.3-.6.3-.5.9-1.7.1-.2 0-.4 0-.6l-1-2.4c-.3-.6-.5-.5-.7-.5h-.6c-.2 0-.6.1-.9.4-.3.3-1.2 1.1-1.2 2.8s1.2 3.2 1.4 3.5c.2.2 2.4 3.7 5.8 5.1 2.2.9 3 1 4.1.8.7-.1 1.9-.8 2.2-1.5.3-.8.3-1.4.2-1.5-.1-.2-.3-.2-.6-.4z"/></svg></a>';
    }

    public function footer(string $name, array $contact): string
    {
        $bits = [];
        foreach (['phone', 'email', 'address'] as $k) {
            if (!empty($contact[$k])) {
                $bits[] = self::e($contact[$k]);
            }
        }
        return '<footer class="wsx-footer"><div class="wsx-wrap"><b>' . self::e($name) . '</b>' . ($bits ? '<div class="wsx-foot-c">' . implode(' · ', $bits) . '</div>' : '')
            . '<div class="wsx-foot-b">© ' . date('Y') . ' ' . self::e($name) . ' · <a href="https://tourfecto.pro" rel="noopener">' . $this->h('built') . '</a></div></div></footer>';
    }

    /** شريط ثابت تحت على الموبايل (السعر + احجز) */
    public function mobileBar(string $price, string $label, string $href): string
    {
        if ($href === '') {
            return '';
        }
        return '<div class="wsx-mbar">' . ($price !== '' ? '<div><small>' . $this->h('price') . '</small><b><bdi>' . self::e($price) . '</bdi></b></div>' : '<div></div>')
            . '<a class="wsx-btn" href="' . self::e($href) . '"' . (str_starts_with($href, 'http') ? ' target="_blank" rel="noopener"' : '') . '>' . self::e($label) . '</a></div>';
    }
}
