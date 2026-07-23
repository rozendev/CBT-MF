const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

// Install playwright if not present
try {
  require.resolve('playwright');
} catch (e) {
  console.log('Playwright not found. Installing playwright...');
  execSync('npm install playwright', { stdio: 'inherit' });
  console.log('Installing Playwright Chromium...');
  execSync('npx playwright install chromium', { stdio: 'inherit' });
}

async function runTest() {
  console.log('Starting kick and ban test script...');
  const launchOptions = {
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  };
  
  if (fs.existsSync('/usr/bin/chromium')) {
    console.log('Using host Chromium at /usr/bin/chromium');
    launchOptions.executablePath = '/usr/bin/chromium';
  } else {
    console.log('Using Playwright bundled Chromium');
  }

  const browser = await chromium.launch(launchOptions);

  // Helper to log in a user
  async function login(page, username, password) {
    await page.goto('http://localhost:8080/login');
    await page.fill('#username', username);
    await page.fill('#passwordField', password);
    await page.click('#btnLogin');
    // Wait for URL to change away from login
    await page.waitForFunction((url) => !window.location.href.includes('/login'), {}, { timeout: 10000 });
  }

  // Set up student 1
  const student1Context = await browser.newContext();
  const student1Page = await student1Context.newPage();
  student1Page.on('console', msg => console.log(`[Student 1 Console] ${msg.text()}`));

  // Set up admin
  const adminContext = await browser.newContext();
  const adminPage = await adminContext.newPage();
  adminPage.on('console', msg => console.log(`[Admin Console] ${msg.text()}`));
  // Auto-accept confirmation dialogs in admin session
  adminPage.on('dialog', async dialog => {
    console.log(`[Admin Dialog] Accepting: "${dialog.message()}"`);
    await dialog.accept();
  });

  // Set up student 2
  const student2Context = await browser.newContext();
  const student2Page = await student2Context.newPage();
  student2Page.on('console', msg => console.log(`[Student 2 Console] ${msg.text()}`));

  try {
    // ----------------------------------------------------
    // TEST 1: Proctor Lock (Student 1)
    // ----------------------------------------------------
    console.log('\n=== TEST 1: Proctor Lock (Student 1: SAS202608012) ===');
    
    console.log('Logging in Student 1 (SAS202608012)...');
    await login(student1Page, 'SAS202608012', 'sayasukakamu');
    console.log('Student 1 logged in. Navigating to exam page...');
    
    await student1Page.goto('http://localhost:8080/student/exam/take/1');
    // Verify student is on exam page
    await student1Page.waitForSelector('text=Ujian', { timeout: 10000 });
    console.log('Student 1 successfully loaded the exam page.');

    console.log('Logging in Admin (superadmin)...');
    await login(adminPage, 'superadmin', 'sayasukakamu');
    console.log('Admin logged in. Navigating to live proctoring page...');
    
    await adminPage.goto('http://localhost:8080/proctor/live/1');
    await adminPage.waitForSelector('text=Daftar Peserta Ujian', { timeout: 10000 });
    console.log('Admin loaded live proctoring page.');

    // Look for Student 1 row
    const student1Row = adminPage.locator('tr', { hasText: 'SAS202608012' });
    await student1Row.waitFor({ state: 'visible', timeout: 5000 });
    console.log('Found Student 1 row in proctor list.');

    // Click "Kunci" button for Student 1
    const lockButton = student1Row.locator('button:has-text("Kunci")');
    console.log('Clicking "Kunci" for Student 1...');
    await lockButton.click();

    // Verify Student 1 is kicked out
    console.log('Waiting for Student 1 to be kicked and redirected...');
    const swalTitle1 = student1Page.locator('.swal2-title');
    await swalTitle1.waitFor({ state: 'visible', timeout: 15000 });
    const text1 = await swalTitle1.innerText();
    console.log(`Student 1 saw modal title: "${text1}"`);

    // Click "OK" on SweetAlert to trigger redirect
    const swalConfirmButton = student1Page.locator('.swal2-confirm');
    if (await swalConfirmButton.isVisible()) {
      await swalConfirmButton.click();
    }
    
    // Wait for student to be redirected to login page
    await student1Page.waitForURL('**/login', { timeout: 10000 });
    console.log('SUCCESS: Student 1 was kicked and redirected to login page.');

    // ----------------------------------------------------
    // TEST 2: Reset Attempt (Student 2)
    // ----------------------------------------------------
    console.log('\n=== TEST 2: Reset Attempt (Student 2: SAS202608024) ===');
    
    console.log('Logging in Student 2 (SAS202608024)...');
    await login(student2Page, 'SAS202608024', 'sayasukakamu');
    console.log('Student 2 logged in. Navigating to exam page...');
    
    await student2Page.goto('http://localhost:8080/student/exam/take/1');
    // Verify student is on exam page
    await student2Page.waitForSelector('text=Ujian', { timeout: 10000 });
    console.log('Student 2 successfully loaded the exam page.');

    // In the admin session, reload to refresh list or look for Student 2 row
    console.log('Admin session: locating Student 2 (SAS202608024) row...');
    const student2Row = adminPage.locator('tr', { hasText: 'SAS202608024' });
    await student2Row.waitFor({ state: 'visible', timeout: 5000 });
    console.log('Found Student 2 row in proctor list.');

    // Click "Reset" button for Student 2
    const resetButton = student2Row.locator('button:has-text("Reset")');
    console.log('Clicking "Reset" for Student 2...');
    await resetButton.click();

    // Verify Student 2 is kicked out
    console.log('Waiting for Student 2 to be kicked and redirected...');
    const swalTitle2 = student2Page.locator('.swal2-title');
    await swalTitle2.waitFor({ state: 'visible', timeout: 15000 });
    const text2 = await swalTitle2.innerText();
    console.log(`Student 2 saw modal title: "${text2}"`);

    // Click OK on SweetAlert
    const swalConfirmButton2 = student2Page.locator('.swal2-confirm');
    if (await swalConfirmButton2.isVisible()) {
      await swalConfirmButton2.click();
    }

    // Wait for student to be redirected to login page
    await student2Page.waitForURL('**/login', { timeout: 10000 });
    console.log('SUCCESS: Student 2 was kicked and redirected to login page.');

    console.log('\n--- ALL TESTS COMPLETED SUCCESSFULLY ---');
  } catch (err) {
    console.error('An error occurred during test execution:', err);
    process.exit(1);
  } finally {
    await browser.close();
  }
}

runTest();
