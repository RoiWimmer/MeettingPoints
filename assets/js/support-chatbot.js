(function () {
  if (window.HiburimSupportChatbotLoaded) return;
  window.HiburimSupportChatbotLoaded = true;

  const STORAGE_KEY = 'hiburim.supportChatbot.history';
  const OPENING_MESSAGE = 'שלום, אני כאן לתמיכה טכנית בשימוש במערכת נקודות חיבור. אפשר לשאול על דיווח חדש, מסך הדיווחים, סטטוסים, תובנות AI, פרופיל או ניווט.';
  const OUT_OF_SCOPE = 'צ׳אט זה מיועד לתמיכה טכנית בתוך המערכת בלבד. כדי לדווח על מקרה של קשיש, יש להשתמש במסך דיווח חדש / צ׳אט דיווח.';

  const answers = [
    {
      patterns: ['דיווח חדש', 'יוצרים דיווח', 'איך יוצרים', 'פתיחת דיווח', 'צ׳אט דיווח', 'צאט דיווח'],
      answer: 'כדי ליצור דיווח חדש נכנסים ל״צ׳אט דיווח״ מהתפריט, מתארים בקצרה מה קרה, מאשרים את ניסוח המקרה ואת רמת הדחיפות, ואז לוחצים על ״אישור ויצירת דיווח״.'
    },
    {
      patterns: ['סטטוס', 'מעדכנים סטטוס', 'עדכון סטטוס', 'בטיפול', 'טופל'],
      answer: 'עדכון סטטוס נעשה במסך ״דיווחים״. מנהל עמותה יכול לפתוח את בחירת הסטטוס ליד הדיווח ולעדכן ל״הוגש״, ״בטיפול״ או ״טופל״. מתנדבים יכולים לצפות בדיווחים שלהם, אך עדכון סטטוס מנוהל על ידי העמותה.'
    },
    {
      patterns: ['מסך הדיווחים', 'דיווחים', 'חיפוש דיווח', 'סינון'],
      answer: 'במסך ״דיווחים״ אפשר לחפש לפי טקסט, מספר דיווח, מתנדב, קשיש, תאריך, סטטוס ודחיפות. הרשימה מציגה רק דיווחים שמותרים למשתמש הנוכחי לפי ההרשאות שלו.'
    },
    {
      patterns: ['ai', 'AI', 'תובנות', 'דוחות AI', 'דוח חכם', 'אנליטיקה'],
      answer: 'מסך ״תובנות״ ו״דוחות AI״ מיועד למנהלי עמותה. הוא מציג סיכום דחיפויות, סטטוסים, צרכים חוזרים, חוסרי משאבים ודוחות חכמים על בסיס הדיווחים של אותה עמותה בלבד.'
    },
    {
      patterns: ['דחיפות', 'רמת דחיפות', 'קריטית', 'גבוהה', 'בינונית', 'נמוכה'],
      answer: 'ביצירת דיווח המערכת ממליצה על רמת דחיפות לפי תיאור המקרה. המשתמש חייב לאשר את ההמלצה או לבחור רמה אחרת לפני שמירת הדיווח. במקרה קריטי תופיע גם אזהרה לפנות מיד לגורם חירום או איש מקצוע מתאים.'
    },
    {
      patterns: ['פרופיל', 'הגדרות', 'סיסמה', 'טלפון', 'אימייל', 'מייל'],
      answer: 'במסך ״פרופיל״ אפשר לעדכן שם, טלפון, אימייל, אזור פעילות והעדפות התראה. סוג החשבון והעמותה מוצגים לקריאה בלבד. החלפת סיסמה דורשת את הסיסמה הנוכחית אם המערכת תומכת בסיסמאות.'
    },
    {
      patterns: ['התחברות', 'login', 'ניווט', 'תפריט', 'איפה נמצא', 'מסך הבית'],
      answer: 'הניווט הראשי נמצא בתפריט הצד. מתנדבים רואים בעיקר דיווחים, צ׳אט דיווח ופרופיל; מנהלי עמותה רואים גם תובנות, דוחות AI ויומן. במסכים קטנים הניווט מופיע בתחתית.'
    }
  ];

  function loadHistory() {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      const parsed = raw ? JSON.parse(raw) : null;
      return Array.isArray(parsed) && parsed.length ? parsed : [{ type: 'bot', text: OPENING_MESSAGE }];
    } catch (error) {
      return [{ type: 'bot', text: OPENING_MESSAGE }];
    }
  }

  function saveHistory(history) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history.slice(-30)));
    } catch (error) {
      // Session history is optional; the widget should keep working without storage.
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function isReportCase(text) {
    return /(נפל|נפלה|ריח גז|גז|שריפה|אש|עשן|לא נושם|תרופות|תרופה|כואב|חולה|קשיש|קשישה|בדידות|בודד|בודדה|צריך עזרה|מצוקה)/u.test(text);
  }

  function isUnrelated(text) {
    return /(בדיחה|מתכון|מזג אוויר|שיר|תכנות|קוד|רפואה|אבחון|ייעוץ רפואי|שאלה כללית|מי אתה|מה אתה)/u.test(text);
  }

  function findAnswer(text) {
    const clean = String(text || '').trim();

    if (!clean) {
      return 'אפשר לכתוב לי שאלה קצרה על שימוש במערכת, למשל איך יוצרים דיווח חדש או איך מעדכנים סטטוס.';
    }

    for (const item of answers) {
      if (item.patterns.some(pattern => clean.includes(pattern))) {
        return item.answer;
      }
    }

    if (isReportCase(clean) || isUnrelated(clean)) {
      return OUT_OF_SCOPE;
    }

    return 'אני יכול לעזור רק בשאלות טכניות על שימוש במערכת: יצירת דיווח, מסך הדיווחים, סטטוסים, תובנות AI, פרופיל, התחברות וניווט.';
  }

  function createWidget() {
    const root = document.createElement('div');
    root.className = 'support-chatbot';
    root.innerHTML = `
      <button type="button" class="support-chatbot__toggle" aria-label="פתיחת תמיכה טכנית" title="תמיכה טכנית">
        <i class="fa-solid fa-headset"></i>
      </button>
      <section class="support-chatbot__panel" aria-label="צ׳אט תמיכה טכנית">
        <header class="support-chatbot__header">
          <div class="support-chatbot__title"><i class="fa-solid fa-circle-question"></i><span>תמיכה טכנית</span></div>
          <button type="button" class="support-chatbot__close" aria-label="סגירת צ׳אט"><i class="fa-solid fa-minus"></i></button>
        </header>
        <div class="support-chatbot__messages" aria-live="polite"></div>
        <form class="support-chatbot__form">
          <input class="support-chatbot__input" type="text" placeholder="איך אפשר לעזור?" autocomplete="off">
          <button class="support-chatbot__send" type="submit" aria-label="שליחה"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
      </section>
    `;

    document.body.appendChild(root);
    return root;
  }

  function init() {
    const root = createWidget();
    const toggle = root.querySelector('.support-chatbot__toggle');
    const close = root.querySelector('.support-chatbot__close');
    const messagesEl = root.querySelector('.support-chatbot__messages');
    const form = root.querySelector('.support-chatbot__form');
    const input = root.querySelector('.support-chatbot__input');
    let history = loadHistory();

    function render() {
      messagesEl.innerHTML = history.map(message => `
        <div class="support-chatbot__msg support-chatbot__msg--${message.type === 'user' ? 'user' : 'bot'}">${escapeHtml(message.text)}</div>
      `).join('');
      messagesEl.scrollTop = messagesEl.scrollHeight;
      saveHistory(history);
    }

    function addMessage(type, text) {
      history.push({ type, text });
      render();
    }

    toggle.addEventListener('click', () => {
      root.classList.toggle('is-open');
      if (root.classList.contains('is-open')) {
        setTimeout(() => input.focus(), 80);
      }
    });

    close.addEventListener('click', () => {
      root.classList.remove('is-open');
    });

    form.addEventListener('submit', event => {
      event.preventDefault();
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      addMessage('user', text);

      const typing = document.createElement('div');
      typing.className = 'support-chatbot__msg support-chatbot__msg--bot support-chatbot__typing';
      typing.textContent = 'בודק...';
      messagesEl.appendChild(typing);
      messagesEl.scrollTop = messagesEl.scrollHeight;

      setTimeout(() => {
        typing.remove();
        addMessage('bot', findAnswer(text));
      }, 450);
    });

    render();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
