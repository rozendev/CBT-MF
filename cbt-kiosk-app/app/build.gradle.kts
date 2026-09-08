import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "id.sch.cbt.kiosk"
    compileSdk = 36

    defaultConfig {
        applicationId = "id.sch.cbt.kiosk"
        minSdk = 28
        targetSdk = 36
        versionCode = 2
        versionName = "1.1.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }
    
    buildFeatures {
        viewBinding = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    signingConfigs {
        // Kunci rilis tinggal di luar repo; path dan sandinya dibaca dari
        // ~/.gradle/gradle.properties. Kalau propertinya tidak ada — mesin lain,
        // CI, kontributor baru — varian rilis tetap bisa dibangun, hanya keluar
        // tanpa tanda tangan. Lebih baik begitu daripada build yang gagal total
        // hanya karena tidak memegang kunci.
        val storePath = providers.gradleProperty("CBT_KIOSK_STORE_FILE").orNull
        if (storePath != null) {
            create("release") {
                storeFile = file(storePath)
                storePassword = providers.gradleProperty("CBT_KIOSK_STORE_PASSWORD").get()
                keyAlias = providers.gradleProperty("CBT_KIOSK_KEY_ALIAS").get()
                keyPassword = providers.gradleProperty("CBT_KIOSK_KEY_PASSWORD").get()
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.findByName("release")
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(JvmTarget.JVM_17)
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.17.0")
    implementation("androidx.appcompat:appcompat:1.7.1")
    implementation("com.google.android.material:material:1.14.0")
    implementation("androidx.webkit:webkit:1.16.0")

    // Root detection; 0.1.2 supports current Android devices and 16 KB pages.
    implementation("com.scottyab:rootbeer-lib:0.1.2")

    // Testing
    testImplementation("junit:junit:4.13.2")
    androidTestImplementation("androidx.test.ext:junit:1.3.0")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.7.0")
}
