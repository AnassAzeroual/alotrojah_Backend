# أسئلة الاجتماع مع مدير الجمعية والمعلمين

الأسئلة التي عليها (مهم) إجبارية إن ضاق الوقت.

## 1. هيكل الجمعية

1) كم عدد المراكز والفروع؟ وهل ينتقل الطلاب بين المراكز أم كل مركز مستقل تماما؟ (مهم)
1 : just one but we can keep the same system of multi centers,
2) كم عدد الحلقات في كل مركز؟ وكم متوسط الطلاب في الحلقة؟ وهل يدرس المعلم الواحد أكثر من حلقة؟ (مهم)
2: hala9at multiple, students can be 3 or more,.the teacher have just one session but can delegate the session to other teachers,
3) هل لكل حلقة معلم واحد فقط، أم يوجد بدلاء ومساعدون يدخلون النقاط أيضا؟
3: just the teacher who enter the points for the students and sometimes, send point's by hand using WhatsApp to other teachers to use there emails to enter and set the point's to there students ,
4) الناظر ومجلس الإدارة: هل يوجد واحد لكل مركز أم واحد لكل الجمعية؟
4: just one and there is just one association,

## 2. المستخدمون والصلاحيات

5) من سيدخل البيانات فعليا: المعلمون بهواتفهم بعد كل حصة؟ أم كاتب على حاسوب مرة في الأسبوع؟ (مهم)
5: the teachers enter the points every day using there phones, surprisingly all teachers using phone not laptops , but the manager use the PC and he want the solution to use Mobile first then the laptop when talking about UI/UX,
6) هل يحتاج أولياء الأمور حسابات لرؤية تقدم أبنائهم، أم يكفي التقرير الورقي؟
6: yes , the students and there guardians need accounts, but in our solution will be one account used by student and there guardians (students or Guardians the same interface for both and one account),
7) من يحق له تعديل أو حذف نقطة بعد إدخالها: المعلم؟ الناظر فقط؟ لا أحد؟ (مهم)
7: teachers, the responsible one for that group, the teacher associated to that group specifically, if the responsible not there and send the not's using WhatsApp to other teacher, the responsible need to give access to the other teacher using the a token for 2 h of time using a link (button to give access to others by generating a link with a token in parameter to the teacher class students, the link can be 15 min or 30min 1h or 2 h, depending on the params in the page giving access , then the link will be sent using WhatsApp to the other teacher , i hope there is a free API to WhatsApp to send link using the teacher number that we have in database ) ,
8) اللغة: العربية فقط أم العربية + الفرنسية؟
8: Arabic first , but we will use ngx-translate with 3 json files (ar,fr,en),

## 3. المنهج: المستويات والموسم والخطة

9) هل تستعملون بالضبط المستويات الثلاثة من الكتاب (نصف / 3 أثمان / ربع)، أم توجد إيقاعات أخرى مثل اليومي أو المكثف الصيفي؟ (مهم)
9: first they given me the book as introduction, but later they want a flexibility in all things like , the student are two types (children's +4 and Adults +18 ), so the Hifz is adjusted to both types like the children's can memorize Surah An-Nas or the first verses of Surah Al-Baqarah, from verse 1 to verse 5. and the Adults can use thumun like (1 Hizeb = 8 thumun's ) or roboaa (1 Hizeb = 4 Roboaa) or Nissf (1 Hizeb = 2 Nissf) or 1 Hizeb or 2 in the week as a capacity of memorizing (Hifed), so both of those two systems need to be selected by students and teachers interface to use what is capable of example of select (by Surah or by Thumun)
10) هل الموسم دائما 37 أسبوعا و 6 فصول و 111 حصة، أم يتغير كل سنة؟ (مهم)
10 : they use 6 weeks and the 7 one is used for the review (preparation) and Quiz at the End, i understand that they want a flexibility, like there is in the interface a button to add (الموسم) with all the (37 أسبوعا / 6 فصول / 111 حصة) in the agenda, but with the edit options for anything (loosely coupled), so (التقويم يتولد تلقائي) when we click to add it as template but we have the full control to change add or remove edit create new things to it,
11) كم حصة في الأسبوع فعليا، دائما 3؟ وماذا يحدث للحصص الفائتة في رمضان والعطل: تلغى أم تؤجل أم تسجل معذورة؟ (مهم)
11 : 3 days (Monday, Wednesday, Friday), but they want also a flexibility on this the teacher can change the day and when is changed, there is a broadcast news related to teachers and students related to that teacher who changed that day (it's students), also to mention a feature of a page to broadcast news based on selection the who is concerned a bout this news, can select news type general (all) , teachers (just teachers), manager (just to the manager), or my students (students associated with the teacher creating the news),
12) هل لكل طالب خطة فصل مكتوبة (هدفي / من / إلى) أم للمتقدمين فقط؟
12: as we already said, the students has the choice to use thumun or Surah both systems need to be supported in this database and the app in general (هدفي / من / إلى) for both thuman and Surah even if the sourah is small use From and To, and the same thing for Thumun (From, To),
13) هل يسجل المعلمون بالحزب والثمن مثل الكتاب، أم بالسورة والآية أيضا؟ (مهم)
13: both the Thumun (From, To) system or Surah (From, To) system, in the week (3 days or more), the goal here is not counting the days but if the student did complete and memorize the Surah or Thumun (it's goal for the week), there are other point's besides the memorization it's the presence in the class and Tajuide Quran, the total will be in 20/20 (presence 2 points, Tajuide 2 points, both can be adjustable),

## 4. التنقيط والتقييم

14) هل قاعدة مواظبة /3 + أداء /17 = /20 مطبقة بنفس الطريقة في كل المراكز وعند كل المعلمين؟ أم يوجد من يستعمل تقسيما مختلفا؟ (مهم)
14: need to be unified, so Hifed (حفض) 14 points, Mowathaba (حضور أو مواظبة) 4 points and Tajwid (تجويد) 2 points, but is adjustable by the manager , a page only manager have access to, once applied is applicable to all teachers,
15) سلم المراجعة (جيد جدا / جيد / اضبط أكثر): هل له مقابل رقمي رسمي أم هو وصفي فقط؟ (مهم)
15: no جيد جدا / جيد / اضبط أكثر just numbers and it's 20/20 point's and ajustable for Hifed and mowathaba and Tajwid as said before,
16) هل صيغة الاختبارات دائما 5 أسئلة لاختبار الفصل + 7 للنهائي + اختبار اكتمال الحزب؟ ومن يختبر: معلم الطالب نفسه أم مختبر خارجي؟ (مهم)
16: for the number of questions, we can make this flexible as well, by generating 5 ayaht to start with in the Thumun or in the hiseb and we let the teacher or the manager to adjust the order or change the list of Ayaht hem self, the teacher is the one who do the quiz and test the student by week or by term (fasle) or the final test in the fasle number 6 (the final),
17) كيف يحسب المعدل العام: متوسط بسيط (حفظ + مراجعة + اختبار) أم مرجح؟ نريد الصيغة الدقيقة. (مهم)
now that we said that even the reviewer of memorization has a not itself like 18/20,12/20 or 5/20,
so the way they calculate it the 
((total of all the weeks for the reviewer notes/all the weeks) + (total of all the weeks for (the Hifed + mowathaba + Tajwid notes)/all the weeks) + quiz fasle 1 note + quiz fasle 2 note + quiz fasle 3 note + quiz fasle 4 note + quiz fasle 5 note) / 8
18) لوحة الشرف (تشجيع / انتبه): من يقررها؟ وهل تترتب عليها نتائج مثل جائزة أو استدعاء ولي؟
the teacher and the manager both should have the access to do it , yes there is gifts adn the guardians are called a they celebrate , the final note are sent using the WhatsApp API to the guardians number or Students number 
## 5. التقارير والإحصائيات

19) ما هي التقارير التي يجب طباعتها مطابقة لصفحات الكتاب (المتابعة الأسبوعية، تقرير الفصل، تقرير الموسم)؟ رتبوها بالأولوية.
yes all like the book but we have the freedam to do something better
20) ما هي الرسوم البيانية الثلاثة التي سيفتحها المدير كل أسبوع؟ مثلا الحضور لكل حلقة، الحفظ لكل مستوى، الأوائل والمتعثرون.
yes, and multiple pages for all statistics that we can do using the database
21) هل تقارن المراكز ببعضها، أم بيانات كل مركز خاصة به؟
yes they do to incorrage the students using WhatsApp , we can print the page as pdf or image and button to send to WhatsApp
## 6. البيانات والترحيل

22) هل يجب استيراد دفاتر ورقية قديمة ولو المجاميع فقط لكل طالب، أم نبدأ من الصفر هذا الموسم؟ (مهم)
yes, they already started and asked me if this app can be ready next month, the first (Fasle) will end after 3 weeks
23) هل توجد قوائم طلاب في Excel؟ إن نعم نريد نسخة منها. (مهم)
they tell that they working for a file in Excel for me so i can update the database
24) هل توجد أدوات مستعملة حاليا مثل حساب ahlquran.com أو مجموعات واتساب للنقاط يجب الربط معها أو تعويضها؟
no, no account , just books
## 7. العملي والتقني

25) الإنترنت في الأقسام: مستقر أم ضعيف أم منعدم؟ (مهم)
they use just phones , the manager use PC, internet the students use (*6) in morocco witch is a pack of sociale media (whatsApp,Facebook, Instagram,Snapchat), internet is normal, not fiber optic just 3G and 4G mobiles  
26) هل يملك المعلمون هواتف ذكية؟ وما نوعها؟ وهل يوجد حاسوب مشترك في المركز؟
Yes teachers has phones, the Manager has PC personal, but if required they can buy a PC or tablette later,
27) الاستضافة: هل لديهم سيرفر أو استضافة أم نبحث عن استضافة رخيصة؟ ومن يدفع الاشتراك السنوي؟
I bought https://alotrojah.ma/,
28) من يصون التطبيق بعد التسليم؟
Me
29) هل يجب أن يكون التطبيق جاهزا لبداية أي موسم أو فصل؟ وما هو الحد الأدنى المقبول للنسخة الأولى؟ (مهم)
1 month for the first MVP, but i said 1 month to buy the time to do something good
## سؤال ختامي

إذا كانت النسخة الأولى تفعل الحضور والنقاط الأسبوعية والتقارير فقط في الفصل القادم، هل هذا مقبول؟

X : the week can have 3 sessions or more, one of them can be review session (with a teacher of review the memorization), two types of teachers (first is teacher of hifed, the one that enter 3 points 'the hifed point', 'the tajwid point','the mowathaba point', the second teacher is the teacher of review memorization is entering the point of memorization like 15/20 or 18/20), both have the same page but the inputs disabled or activated based on type of teacher, the teacher of review has input called review memorization in the same line with the other input of the hifed input of the other teacher witch should be disabled,

## نتائج الاجتماع — قرارات نهائية (بعد اللقاء مع المدير والمعلمين)

### C-a. التنقيط: 14+4+2 = 20، بنظام modules مرن
- الحفظ 14 + المواظبة 4 + التجويد 2 = 20/20. المدير يعدل القيم من صفحة خاصة، والمجموع يجب أن يبقى 20.
- أي كتاب جديد (مثلا السراج في بيان غريب القرآن) = module جديد يضيفه المدير، بدون تغيير الكود.
- R2 معتمد: السراج يُدخل كل حصة مثل الحفظ لكن بمجموع **مستقل /20** خارج الـ 20 الأسبوعية.
- المتوسط الأسبوعي للسراج = simple average لنقط /20 للحصص، فقط عندما يكون module مفعّلا. غير المفعّل = رمادي disabled في Angular ولا يدخل أي حساب (الفrontend + الـ views تراعي is_active=1).

### C-b. المعدل العام: صيغة مرنة بدون hardcode
- final = (avg_murajaa + avg_weekly + مجموع متوسطات اختبارات الفصول بما فيها النهائي) / (2 + عدد الفصول).
- الافتراضي 6 فصول يعطي /8. عدد الفصول مرن (7 أو أكثر). قالب الـ 6 فصول زر في الواجهة فقط.

### C-c. التقويم: القالب الافتراضي 42 أسبوعا
- 6 فصول × 7 أسابيع (6 حفظ + 1 مراجعة واختبار الفصل). المدير يعدل بحرية (مثلا 4 + 1).
- لا ننتظر ملف Excel: القالب يُبنى في زر Laravel، والبيانات الحقيقية تُدخل عند إنشائهم للموسم.

### معلم المراجعة: تقييم دوري وليس لكل حصة (معتمد)
- المعلم نوعان: تحفيظ (يدخل الحفظ+التجويد+المواظبة+السراج) ومراجعة (يدخل نقطة رسمية /20 لكل دورة مراجعة تغطي 1-3 أسابيع حسب كمية الحفظ).
- `teacher_type` يتحكم في تفعيل/تعطيل الحقول في نفس الصفحة.
- `murajaa_reviews` هي النقطة الرسمية الداخلة في avg_murajaa. أما `revision_logs` فسجل تدريب اختياري لكل حصة.

### المواظبة: إدخال يدوي (معتمد)
- نقطة المواظبة /4 يدخلها معلم التحفيظ يدويا كل حصة. لا اشتقاق تلقائي من جدول الحضور (جدول `attendance` يبقى سجل حضور مستقل).

### ملفات قاعدة البيانات (Desktop — نسخة مدمجة نهائية، بدون migrations)
- quran_memorization_db.sql = الهيكل الكامل + كل التعديلات مدمجة (42 أسبوعا، scoring modules، murajaa_reviews، كل الجداول والفيوهات).
- quran_seed_data.sql = بيانات تجريبية مطابقة للهيكل (3 مراكز معزولة، 42 أسبوعا/126 حصة، 168 نقطة، surah-mode، sarraj، دورات مراجعة).
- quran_check_queries.sql = استعلامات التحقق ومحاكاة الصفحات (A فحوصات العزل، B الصفحات، C الشارتات، D الميزات الجديدة).

### نقاط تقنية ثابتة
- Mobile-first (المعلمون هواتف، المدير PC). عربي أولا + fr/en عبر ngx-translate.
- لا WhatsApp API مدفوع: روابط wa.me بأرقام الأولياء من قاعدة البيانات.
- تفويض الدخول عبر token links (15/30/60/120 دقيقة) تُرسل في WhatsApp.
- الأخبار: all / teachers / manager / my_students.
- النطاق alotrojah.ma. الصيانة: أنا. MVP شهر واحد. Excel لاحقا عند توفره.
