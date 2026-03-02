<?php
require_once __DIR__ . '/app/bootstrap.php';

bugcatcher_start_session();

// If already logged in, go to dashboard
if (isset($_SESSION['id'])) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BugCatcher - Issue Tracking System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

         body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
    
    background: 
        url(https://i.pinimg.com/1200x/b2/13/65/b21365c035ff1cfa52edc492affa885b.jpg);

    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    background-repeat: no-repeat;

    min-height: 100vh;
}
        /* Navbar */
        .navbar {
            background: rgba(13, 17, 23, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            border-bottom: 1px solid #30363d;
        }

        .logo {
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logo-icon {
            font-size: 1.8rem;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .nav-links a {
            color: #c9d1d9;
            text-decoration: none;
            margin-left: 1.5rem;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .nav-links a:hover {
            color: #2da44e;
        }

        /* Hero Section with Animation */
        .hero {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 6rem 2rem 2rem;
            position: relative;
            min-height: 100vh;
        }

        /* Animation Container */
        .animation-container {
            position: relative;
            width: 600px;
            height: 300px;
            margin-bottom: 2rem;
        }

        /* Ground line */
        .ground {
            position: absolute;
            bottom: 50px;
            left: 50px;
            right: 50px;
            height: 2px;
            background: linear-gradient(90deg, transparent, #30363d, #30363d, transparent);
        }

        /* Decorative grass blades */
        .grass {
            position: absolute;
            bottom: 52px;
            width: 4px;
            background: linear-gradient(180deg, #2da44e, #238636);
            border-radius: 2px 2px 0 0;
            opacity: 0.6;
        }

        .grass:nth-child(1) { left: 80px; height: 25px; animation: sway 3s ease-in-out infinite; }
        .grass:nth-child(2) { left: 120px; height: 18px; animation: sway 3s ease-in-out infinite 0.5s; }
        .grass:nth-child(3) { left: 450px; height: 22px; animation: sway 3s ease-in-out infinite 1s; }
        .grass:nth-child(4) { left: 500px; height: 15px; animation: sway 3s ease-in-out infinite 1.5s; }

        @keyframes sway {
            0%, 100% { transform: rotate(-3deg); }
            50% { transform: rotate(3deg); }
        }

        /* CSS-DRAWN BUG - Cartoon style */
        .bug {
            position: absolute;
            bottom: 65px;
            left: 450px;
            width: 75px;
            height: 58px;
            z-index: 10;
            animation: bugMove 8s cubic-bezier(0.25, 0.46, 0.45, 0.94) infinite;
            transform-origin: center bottom;
            will-change: transform, left;
        }

        /* Bug body - red shell */
        .bug-body {
            position: absolute;
            width: 68px;
            height: 52px;
            background: linear-gradient(145deg, #dc2626 0%, #991b1b 100%);
            border-radius: 50% 50% 45% 45%;
            top: 5px;
            left: 3.5px;
            box-shadow: 
                inset -3px -3px 8px rgba(0,0,0,0.3),
                inset 3px 3px 8px rgba(255,255,255,0.2),
                0 4px 8px rgba(0,0,0,0.3);
        }

        /* Bug spots */
        .bug-body::before {
            content: '';
            position: absolute;
            width: 14px;
            height: 14px;
            background: #1f2937;
            border-radius: 50%;
            top: 12px;
            left: 14px;
            box-shadow: 
                26px 4px 0 #1f2937,
                12px 22px 0 #1f2937;
        }

        /* Bug head */
        .bug-head {
            position: absolute;
            width: 30px;
            height: 26px;
            background: linear-gradient(145deg, #374151 0%, #1f2937 100%);
            border-radius: 50%;
            top: -6px;
            left: 22px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        /* Bug eyes */
        .bug-head::before,
        .bug-head::after {
            content: '';
            position: absolute;
            width: 9px;
            height: 9px;
            background: white;
            border-radius: 50%;
            top: 7px;
        }
        .bug-head::before { left: 5px; }
        .bug-head::after { right: 5px; }

        /* Pupils */
        .bug-head span {
            position: absolute;
            width: 5px;
            height: 5px;
            background: #000;
            border-radius: 50%;
            top: 11px;
        }
        .bug-head span:first-of-type { left: 7px; }
        .bug-head span:last-of-type { right: 7px; }

        /* Antennae */
        .antenna {
            position: absolute;
            width: 3px;
            height: 18px;
            background: #1f2937;
            top: -14px;
            border-radius: 2px;
        }
        .antenna.left { left: 27px; transform: rotate(-20deg); }
        .antenna.right { right: 27px; transform: rotate(20deg); }
        .antenna::after {
            content: '';
            position: absolute;
            width: 6px;
            height: 6px;
            background: #1f2937;
            border-radius: 50%;
            top: -2px;
            left: -1px;
        }

        /* Bug legs */
        .bug-leg {
            position: absolute;
            width: 18px;
            height: 5px;
            background: #1f2937;
            border-radius: 2px;
            transform-origin: left center;
        }
        .bug-leg.left1 { top: 18px; left: -12px; transform: rotate(-30deg); animation: legWiggle1 0.3s ease-in-out infinite alternate; }
        .bug-leg.left2 { top: 28px; left: -10px; transform: rotate(-15deg); animation: legWiggle2 0.3s ease-in-out infinite alternate; }
        .bug-leg.left3 { top: 40px; left: -8px; transform: rotate(-5deg); animation: legWiggle3 0.3s ease-in-out infinite alternate; }
        .bug-leg.right1 { top: 18px; right: -12px; transform: rotate(30deg); animation: legWiggle2 0.3s ease-in-out infinite alternate; }
        .bug-leg.right2 { top: 28px; right: -10px; transform: rotate(15deg); animation: legWiggle3 0.3s ease-in-out infinite alternate; }
        .bug-leg.right3 { top: 40px; right: -8px; transform: rotate(5deg); animation: legWiggle1 0.3s ease-in-out infinite alternate; }

        @keyframes legWiggle1 { from { transform: rotate(-30deg); } to { transform: rotate(-45deg); } }
        @keyframes legWiggle2 { from { transform: rotate(0deg); } to { transform: rotate(-15deg); } }
        @keyframes legWiggle3 { from { transform: rotate(25deg); } to { transform: rotate(10deg); } }

        /* Bug shadow */
        .bug-shadow {
            position: absolute;
            bottom: -14px;
            left: 50%;
            transform: translateX(-50%);
            width: 58px;
            height: 14px;
            background: radial-gradient(ellipse, rgba(0,0,0,0.3) 0%, transparent 70%);
            border-radius: 50%;
            animation: bugShadow 8s ease-in-out infinite;
            pointer-events: none;
        }

        @keyframes bugShadow {
            0%, 20% { transform: translateX(-50%) scale(1); opacity: 1; }
            22% { transform: translateX(-50%) scale(0.6); opacity: 0.5; }
            25% { transform: translateX(-50%) scale(0.8); opacity: 0.7; }
            45% { transform: translateX(-50%) scale(0.9); opacity: 0.8; }
            48% { transform: translateX(-50%) scale(0.5); opacity: 0.4; }
            50% { transform: translateX(-50%) scale(0.7); opacity: 0.6; }
            70% { transform: translateX(-50%) scale(1); opacity: 1; }
            72% { transform: translateX(-50%) scale(0.5); opacity: 0.4; }
            75% { transform: translateX(-50%) scale(0.6); opacity: 0.5; }
            95% { transform: translateX(-50%) scale(0.9); opacity: 0.8; }
            100% { transform: translateX(-50%) scale(1); opacity: 1; }
        }

        @keyframes bugMove {
            0%, 18% { left: 450px; transform: scaleX(1) translateY(0); }
            20% { left: 440px; transform: scaleX(1) translateY(-25px); }
            22% { left: 430px; transform: scaleX(1) translateY(-8px); }
            25% { left: 420px; transform: scaleX(-1) translateY(0); }
            45% { left: 420px; transform: scaleX(-1) translateY(0); }
            47% { left: 400px; transform: scaleX(-1) translateY(-18px); }
            50% { left: 380px; transform: scaleX(1) translateY(0); }
            70% { left: 380px; transform: scaleX(1) translateY(0); }
            72% { left: 365px; transform: scaleX(1) translateY(-15px); }
            75% { left: 350px; transform: scaleX(-1) translateY(0); }
            95% { left: 350px; transform: scaleX(-1) translateY(0); }
            97% { left: 400px; transform: scaleX(1) translateY(-22px); }
            100% { left: 450px; transform: scaleX(1) translateY(0); }
        }

        /* Bug panic animation */
        .bug.panic {
            animation: bugPanic 0.25s ease-in-out infinite !important;
        }
        @keyframes bugPanic {
            0%, 100% { transform: scaleX(1) rotate(0deg) translateY(0); }
            25% { transform: scaleX(1) rotate(-25deg) translateY(-10px); }
            50% { transform: scaleX(-1) rotate(20deg) translateY(-5px); }
            75% { transform: scaleX(-1) rotate(-15deg) translateY(-8px); }
        }

        /* Bug caught animation */
        .bug.caught {
            animation: bugCaught 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards !important;
        }
        @keyframes bugCaught {
            0% { transform: scale(1) rotate(0deg); opacity: 1; }
            30% { transform: scale(1.4) rotate(-40deg) translateY(-30px); }
            60% { transform: scale(0.6) rotate(10deg) translateY(10px); opacity: 0.5; }
            100% { transform: scale(0) rotate(0deg) translateY(40px); opacity: 0; }
        }

        /* THE CATCHER (Hero) - CSS Human Character */
        .catcher-container {
            position: absolute;
            bottom: 55px;
            left: 80px;
            z-index: 20;
            width: 70px;
            height: 110px;
            animation: catcherChase 8s cubic-bezier(0.25, 0.46, 0.45, 0.94) infinite;
            will-change: transform, left;
        }

        /* Catcher shadow */
        .catcher-container::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 12px;
            background: radial-gradient(ellipse, rgba(0,0,0,0.25) 0%, transparent 70%);
            border-radius: 50%;
            animation: catcherShadow 8s ease-in-out infinite;
        }

        @keyframes catcherShadow {
            0%, 16% { transform: translateX(-50%) scale(1); opacity: 1; }
            20% { transform: translateX(-50%) scale(0.7); opacity: 0.5; }
            22% { transform: translateX(-50%) scale(0.9); opacity: 0.8; }
            40% { transform: translateX(-50%) scale(1); opacity: 1; }
            44% { transform: translateX(-50%) scale(0.6); opacity: 0.4; }
            46% { transform: translateX(-50%) scale(0.8); opacity: 0.6; }
            64% { transform: translateX(-50%) scale(1); opacity: 1; }
            68% { transform: translateX(-50%) scale(0.6); opacity: 0.4; }
            70% { transform: translateX(-50%) scale(0.8); opacity: 0.6; }
            88% { transform: translateX(-50%) scale(1); opacity: 1; }
            92% { transform: translateX(-50%) scale(0.7); opacity: 0.5; }
            100% { transform: translateX(-50%) scale(1); opacity: 1; }
        }

        @keyframes catcherChase {
            0%, 16% { left: 80px; transform: scaleX(1); }
            20% { left: 200px; transform: scaleX(1); }
            22% { left: 380px; transform: scaleX(1); }
            40% { left: 380px; transform: scaleX(1); }
            44% { left: 350px; transform: scaleX(-1); }
            46% { left: 320px; transform: scaleX(-1); }
            64% { left: 320px; transform: scaleX(-1); }
            68% { left: 300px; transform: scaleX(-1); }
            70% { left: 270px; transform: scaleX(-1); }
            88% { left: 270px; transform: scaleX(-1); }
            92% { left: 170px; transform: scaleX(1); }
            100% { left: 80px; transform: scaleX(1); }
        }

        /* Catcher Head */
        .catcher-head {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 32px;
            height: 36px;
            background: linear-gradient(145deg, #ffdbac 0%, #f5cba7 100%);
            border-radius: 50% 50% 45% 45%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            animation: headBob 0.5s ease-in-out infinite alternate;
            z-index: 10;
        }

        /* Hair */
        .catcher-head::before {
            content: '';
            position: absolute;
            top: -3px;
            left: 50%;
            transform: translateX(-50%);
            width: 36px;
            height: 14px;
            background: linear-gradient(180deg, #2d1810 0%, #1a0f0a 100%);
            border-radius: 50% 50% 30% 30%;
        }

        @keyframes headBob {
            0% { transform: translateX(-50%) rotate(-3deg); }
            100% { transform: translateX(-50%) rotate(3deg); }
        }

        /* Eyes */
        .catcher-head .eye {
            position: absolute;
            width: 6px;
            height: 7px;
            background: #1a1a1a;
            border-radius: 50%;
            top: 13px;
            animation: eyeBlink 3s ease-in-out infinite;
        }

        .catcher-head .eye.left { left: 5px; }
        .catcher-head .eye.right { right: 5px; }

        @keyframes eyeBlink {
            0%, 48%, 52%, 100% { transform: scaleY(1); }
            50% { transform: scaleY(0.1); }
        }

        /* Smile */
        .catcher-head .smile {
            position: absolute;
            bottom: 7px;
            left: 50%;
            transform: translateX(-50%);
            width: 12px;
            height: 4px;
            border-bottom: 2px solid #c44536;
            border-radius: 0 0 50% 50%;
        }

        /* Catcher Body (Blue Shirt) */
        .catcher-body {
            position: absolute;
            top: 33px;
            left: 50%;
            transform: translateX(-50%);
            width: 38px;
            height: 42px;
            background: linear-gradient(145deg, #3b82f6 0%, #2563eb 100%);
            border-radius: 10px 10px 5px 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            z-index: 9;
        }

        /* Shirt collar */
        .catcher-body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 22px;
            height: 8px;
            background: #1d4ed8;
            border-radius: 0 0 11px 11px;
        }

        /* Arms */
        .arm {
            position: absolute;
            top: 38px;
            width: 11px;
            height: 32px;
            background: linear-gradient(145deg, #ffdbac 0%, #f5cba7 100%);
            border-radius: 5px;
            transform-origin: top center;
            z-index: 8;
        }

        .arm.left {
            left: 8px;
            animation: leftArmSwing 0.5s ease-in-out infinite alternate;
        }

        .arm.right {
            right: 8px;
            animation: rightArmSwing 0.5s ease-in-out infinite alternate;
        }

        /* Sleeves */
        .arm::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 11px;
            background: linear-gradient(145deg, #2563eb 0%, #1d4ed8 100%);
            border-radius: 5px 5px 0 0;
        }

        @keyframes leftArmSwing {
            0% { transform: rotate(-30deg); }
            100% { transform: rotate(10deg); }
        }

        @keyframes rightArmSwing {
            0% { transform: rotate(30deg); }
            100% { transform: rotate(-10deg); }
        }

        /* Legs */
        .catcher-leg {
            position: absolute;
            top: 65px;
            width: 12px;
            height: 30px;
            background: linear-gradient(145deg, #1f2937 0%, #111827 100%);
            border-radius: 3px;
            transform-origin: top center;
            z-index: 8;
        }

        .catcher-leg.left {
            left: 16px;
            animation: leftLegRun 0.5s ease-in-out infinite alternate;
        }

        .catcher-leg.right {
            right: 16px;
            animation: rightLegRun 0.5s ease-in-out infinite alternate;
        }

        /* Red Shoes */
        .catcher-leg::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: -2px;
            width: 16px;
            height: 10px;
            background: linear-gradient(145deg, #ef4444 0%, #dc2626 100%);
            border-radius: 5px 5px 2px 2px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        @keyframes leftLegRun {
            0% { transform: rotate(-20deg) translateY(0); }
            100% { transform: rotate(20deg) translateY(-3px); }
        }

        @keyframes rightLegRun {
            0% { transform: rotate(20deg) translateY(-3px); }
            100% { transform: rotate(-20deg) translateY(0); }
        }

        /* Net Container */
        .net-container {
            position: absolute;
            top: -5px;
            right: -25px;
            width: 40px;
            height: 50px;
            z-index: 25;
            animation: netBob 0.4s ease-in-out infinite alternate;
            transform-origin: 30% 90%;
        }

        @keyframes netBob {
            0% { transform: rotate(-10deg) translateY(0); }
            100% { transform: rotate(-25deg) translateY(-8px); }
        }

        /* Net Handle (Wooden) */
        .net-handle {
            position: absolute;
            bottom: 0;
            left: 5px;
            width: 6px;
            height: 45px;
            background: linear-gradient(90deg, #8b5a2b 0%, #a67c52 50%, #8b5a2b 100%);
            border-radius: 3px;
            transform: rotate(-15deg);
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
        }

        /* Net Rim (Red) */
        .net-rim {
            position: absolute;
            top: 0;
            left: 0;
            width: 35px;
            height: 30px;
            background: 
                /* Grid pattern for mesh */
                repeating-linear-gradient(
                    0deg,
                    transparent,
                    transparent 3px,
                    rgba(255,255,255,0.3) 3px,
                    rgba(255,255,255,0.3) 4px
                ),
                repeating-linear-gradient(
                    90deg,
                    transparent,
                    transparent 3px,
                    rgba(255,255,255,0.3) 3px,
                    rgba(255,255,255,0.3) 4px
                ),
                /* Net depth gradient */
                radial-gradient(ellipse at 30% 30%, rgba(255,255,255,0.2) 0%, transparent 60%),
                /* Net color */
                rgba(200, 200, 220, 0.4);
            border: 3px solid #dc2626;
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
            box-shadow: 
                inset 0 0 10px rgba(255,255,255,0.2),
                0 3px 8px rgba(0,0,0,0.3);
        }

        /* Net rim highlight */
        .net-rim::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 50%;
            transform: translateX(-50%);
            width: 80%;
            height: 40%;
            border-top: 2px solid rgba(255,255,255,0.5);
            border-radius: 50%;
        }

        /* Catcher caught the bug - victory animation */
        .catcher-container.victory .catcher-head {
            animation: headVictory 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards !important;
        }

        .catcher-container.victory .catcher-body {
            animation: bodyVictory 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards !important;
        }

        .catcher-container.victory .arm.right {
            animation: armVictory 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards !important;
        }

        .catcher-container.victory .net-container {
            animation: netCatch 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards !important;
        }

        @keyframes netCatch {
            0% { transform: rotate(-15deg) scale(1) translateY(0); }
            30% { transform: rotate(-60deg) scale(1.3) translateY(-25px); }
            50% { transform: rotate(-45deg) scale(1.2) translateY(-15px); }
            70% { transform: rotate(-55deg) scale(1.25) translateY(-20px); }
            100% { transform: rotate(-50deg) scale(1.2) translateY(-15px); }
        }

        @keyframes headVictory {
            0% { transform: translateX(-50%) rotate(0deg); }
            30% { transform: translateX(-50%) rotate(-10deg) translateY(-15px); }
            50% { transform: translateX(-50%) rotate(5deg) translateY(-8px); }
            70% { transform: translateX(-50%) rotate(-5deg) translateY(-12px); }
            100% { transform: translateX(-50%) rotate(0deg) translateY(-8px); }
        }

        @keyframes bodyVictory {
            0% { transform: translateX(-50%) scale(1); }
            30% { transform: translateX(-50%) scale(1.1) translateY(-10px); }
            50% { transform: translateX(-50%) scale(1.05) translateY(-5px); }
            70% { transform: translateX(-50%) scale(1.08) translateY(-8px); }
            100% { transform: translateX(-50%) scale(1.05) translateY(-5px); }
        }

        @keyframes armVictory {
            0% { transform: rotate(-15deg); }
            30% { transform: rotate(-80deg) translateY(-20px); }
            50% { transform: rotate(-65deg) translateY(-10px); }
            70% { transform: rotate(-75deg) translateY(-15px); }
            100% { transform: rotate(-70deg) translateY(-10px); }
        }

        /* Speech bubble - positioned independently outside catcher */
        .speech-bubble {
            position: absolute;
            bottom: 180px;
            left: 50%;
            transform: translateX(-50%) scale(0);
            background: linear-gradient(135deg, #ffffff 0%, #f0f0f0 100%);
            color: #24292f;
            padding: 10px 18px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 700;
            white-space: nowrap;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            z-index: 100;
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            pointer-events: none;
        }

        .speech-bubble.show {
            transform: translateX(-50%) scale(1);
            opacity: 1;
            animation: bubbleBounce 0.5s ease-out;
        }

        /* Speech bubble arrow */
        .speech-bubble::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            border: 10px solid transparent;
            border-top-color: #ffffff;
        }

        @keyframes bubbleBounce {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            70% { transform: scale(0.9); }
            100% { transform: scale(1); }
        }

        @keyframes bubbleBounceFlip {
            0% { transform: scale(0) scaleX(-1); }
            50% { transform: scale(1.2) scaleX(-1); }
            70% { transform: scale(0.9) scaleX(-1); }
            100% { transform: scale(1) scaleX(-1); }
        }

        /* Enhanced dust particles */
        .dust {
            position: absolute;
            bottom: 50px;
            width: 8px;
            height: 8px;
            background: radial-gradient(circle, rgba(139, 148, 158, 0.6) 0%, rgba(139, 148, 158, 0) 70%);
            border-radius: 50%;
            opacity: 0;
        }

        .dust:nth-child(1) { left: 100px; animation: dustKick 8s ease-out infinite 0.2s; }
        .dust:nth-child(2) { left: 120px; animation: dustKick 8s ease-out infinite 0.4s; }
        .dust:nth-child(3) { left: 400px; animation: dustKick 8s ease-out infinite 2.2s; }
        .dust:nth-child(4) { left: 340px; animation: dustKick 8s ease-out infinite 4.2s; }
        .dust:nth-child(5) { left: 300px; animation: dustKick 8s ease-out infinite 6.2s; }

        @keyframes dustKick {
            0%, 100% { opacity: 0; transform: translateY(0) scale(0.3) translateX(0); }
            5% { opacity: 1; transform: translateY(-15px) scale(1) translateX(5px); }
            15% { opacity: 0; transform: translateY(-35px) scale(0.4) translateX(15px); }
        }

        /* Additional trail particles */
        .trail {
            position: absolute;
            bottom: 52px;
            width: 4px;
            height: 4px;
            background: rgba(45, 164, 78, 0.3);
            border-radius: 50%;
            opacity: 0;
        }

        .trail:nth-child(6) { left: 200px; animation: trailFade 8s ease-out infinite 1.8s; }
        .trail:nth-child(7) { left: 250px; animation: trailFade 8s ease-out infinite 3.8s; }
        .trail:nth-child(8) { left: 150px; animation: trailFade 8s ease-out infinite 5.8s; }

        @keyframes trailFade {
            0%, 100% { opacity: 0; transform: scale(0); }
            10% { opacity: 0.6; transform: scale(1); }
            30% { opacity: 0; transform: scale(0.5); }
        }

        /* Caught indicator - Enhanced */
        .caught-indicator {
            position: absolute;
            top: 20px;
            left: 50%;
            background: linear-gradient(135deg, #2da44e, #238636);
            color: white;
            padding: 12px 28px;
            border-radius: 30px;
            font-weight: 800;
            font-size: 1.1rem;
            opacity: 0;
            transform: translateX(-50%) translateY(-20px) scale(0.8);
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            box-shadow: 0 8px 30px rgba(45, 164, 78, 0.5);
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            z-index: 50;
        }

        .caught-indicator.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0) scale(1);
        }

        /* Screen shake effect */
        .shake {
            animation: screenShake 0.4s ease-in-out;
        }

        @keyframes screenShake {
            0%, 100% { transform: translateX(0); }
            10% { transform: translateX(-3px) rotate(-0.5deg); }
            20% { transform: translateX(3px) rotate(0.5deg); }
            30% { transform: translateX(-3px) rotate(-0.5deg); }
            40% { transform: translateX(3px) rotate(0.5deg); }
            50% { transform: translateX(-2px) rotate(-0.3deg); }
            60% { transform: translateX(2px) rotate(0.3deg); }
            70% { transform: translateX(-1px) rotate(-0.2deg); }
            80% { transform: translateX(1px) rotate(0.2deg); }
            90% { transform: translateX(0) rotate(0); }
        }

        /* Hero Content */
        .hero-content {
            max-width: 700px;
            z-index: 10;
        }

        .hero h1 {
            font-size: 3.5rem;
            color: rgb(236, 234, 234);
            margin-bottom: 1rem;
            font-weight: 800;
            line-height: 1.2;
            text-shadow: 0 2px 20px rgba(0,0,0,0.3);
        }

        .hero h1 span {
            background: linear-gradient(90deg, #2da44e, #3fb950);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero p {
            font-size: 1.25rem;
            color: #fdfdfd;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .btn {
            background: linear-gradient(135deg, #27ad4d, #054912);
            color: white;
            padding: 14px 32px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(45, 164, 78, 0.3);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(45, 164, 78, 0.4);
        }

        .btn-secondary {
            background: transparent;
            border: 2px solid #30363d;
            margin-left: 1rem;
            box-shadow: none;
        }

        .btn-secondary:hover {
            background: #21262d;
            border-color: #8b949e;
        }

        /* Stats Section */
        .stats {
            display: flex;
            gap: 4rem;
            margin-top: 3rem;
            padding: 0;
            background: transparent;
            border: none;
            justify-content: center;
        }

        .stat {
            text-align: center;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: #f1efef;
            display: block;
            text-shadow: 0 0 20px rgb(0, 0, 0);
        }

        .stat-label {
            font-size: 0.9rem;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Features Section */
        .features {
            background: #0d1117;
            padding: 5rem 2rem;
        }

        .features-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-title {
            text-align: center;
            margin-bottom: 3rem;
        }

        .section-title h2 {
            color: white;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .section-title p {
            color: #8b949e;
            font-size: 1.1rem;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .feature {
            background: linear-gradient(145deg, #197a31, #045a1e);
            padding: 2rem;
            border-radius: 12px;
            border: 1px solid #30363d;
            transition: all 0.3s ease;
        }

        .feature:hover {
            transform: translateY(-5px);
            border-color: #2da44e;
            box-shadow: 0 10px 30px rgba(45, 164, 78, 0.1);
        }

        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            display: inline-block;
            animation: float 3s ease-in-out infinite;
        }

        .feature:nth-child(2) .feature-icon { animation-delay: 0.5s; }
        .feature:nth-child(3) .feature-icon { animation-delay: 1s; }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .feature h3 {
            color: white;
            margin-bottom: 0.75rem;
            font-size: 1.25rem;
        }

        .feature p {
            color: #8b949e;
            line-height: 1.6;
        }

        /* Footer */
        footer {
            background: #044612;
            padding: 2rem;
            text-align: center;
            border-top: 1px solid #044612;
        }

        footer p {
            color: #ffffff;
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .animation-container {
                width: 100%;
                max-width: 400px;
                height: 200px;
            }

            .hero h1 {
                font-size: 2rem;
            }

            .hero p {
                font-size: 1rem;
            }

            .btn {
                padding: 12px 24px;
                font-size: 1rem;
            }

            .btn-secondary {
                margin-left: 0;
                margin-top: 1rem;
            }

            .stats {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 2rem;
                justify-content: center;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Enhanced panic effect with sweat drops */
        .bug-sweat {
            position: absolute;
            right: -10px;
            top: 0;
            width: 6px;
            height: 6px;
            background: #60a5fa;
            border-radius: 50% 50% 50% 0;
            opacity: 0;
            animation: sweatDrop 8s ease-in-out infinite;
            pointer-events: none;
        }

        .bug-panic {
            position: absolute;
            right: -25px;
            top: 30%;
            font-size: 1.2rem;
            opacity: 0;
            animation: panicLines 8s ease-in-out infinite;
            filter: blur(0.5px);
            pointer-events: none;
        }

        @keyframes panicLines {
            0%, 18% { opacity: 0; transform: translateX(0) scale(0.5); }
            20%, 24% { opacity: 0.8; transform: translateX(10px) scale(1); }
            26% { opacity: 0; transform: translateX(15px) scale(0.5); }
            44%, 48% { opacity: 0.8; transform: translateX(-10px) scale(1); }
            50% { opacity: 0; transform: translateX(-15px) scale(0.5); }
            68%, 72% { opacity: 0.8; transform: translateX(10px) scale(1); }
            74% { opacity: 0; transform: translateX(15px) scale(0.5); }
            100% { opacity: 0; }
        }

        @keyframes sweatDrop {
            0%, 18% { opacity: 0; transform: translateY(0); }
            20% { opacity: 1; transform: translateY(0); }
            25% { opacity: 0; transform: translateY(15px); }
            44% { opacity: 0; transform: translateY(0); }
            46% { opacity: 1; transform: translateY(0); }
            51% { opacity: 0; transform: translateY(15px); }
            68% { opacity: 0; transform: translateY(0); }
            70% { opacity: 1; transform: translateY(0); }
            75% { opacity: 0; transform: translateY(15px); }
            100% { opacity: 0; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="logo">
            <span class="logo-icon">🐞</span>
            BugCatcher
        </div>
        <div class="nav-links">
            <a href="register-passed-by-maglaque/login.php">Login</a>
            <a href="register-passed-by-maglaque/signup.php">Sign Up</a>
        </div>
    </nav>
    
    <div class="hero">
        <!-- Animation Scene -->
        <div class="animation-container">
            <!-- Ground decorations -->
            <div class="grass"></div>
            <div class="grass"></div>
            <div class="grass"></div>
            <div class="grass"></div>
            <div class="ground"></div>
            
            <!-- Dust particles -->
            <div class="dust"></div>
            <div class="dust"></div>
            <div class="dust"></div>
            <div class="dust"></div>
            <div class="dust"></div>
            <!-- Trail particles -->
            <div class="trail"></div>
            <div class="trail"></div>
            <div class="trail"></div>
            
            <!-- The Bug (running away) -->
            <div class="bug" id="bug">
                🐞
                <div class="bug-shadow"></div>
                <div class="bug-sweat"></div>
                <div class="bug-panic">💨</div>
            </div>
            
            <!-- Speech Bubble (outside catcher to prevent flip) -->
            <div class="speech-bubble" id="speechBubble">Gotcha!</div>
            
            <!-- The Catcher (hunting) -->
            <div class="catcher-container" id="catcherContainer">
                <div class="net-container">
                    <div class="net-rim"></div>
                    <div class="net-handle"></div>
                </div>
                <div class="catcher-head">
                    <div class="eye left"></div>
                    <div class="eye right"></div>
                    <div class="smile"></div>
                </div>
                <div class="catcher-body"></div>
                <div class="arm left"></div>
                <div class="arm right"></div>
                <div class="catcher-leg left"></div>
                <div class="catcher-leg right"></div>
            </div>
            
            <!-- Success indicator -->
            <div class="caught-indicator" id="caughtIndicator">Bug Caught!</div>
        </div>

        <div class="hero-content">
            <h1>Track Bugs Like a <span>Pro</span></h1>
            <p>BugCatcher helps you manage and track issues in your projects. Simple, fast, and effective issue tracking for teams of all sizes.</p>
            <div>
                <a href="register-passed-by-maglaque/login.php" class="btn">Get Started</a>
                <a href="#features" class="btn btn-secondary">Learn More</a>
            </div>
            
            <div class="stats">
                <div class="stat">
                    <span class="stat-value">∞</span>
                    <span class="stat-label">Bugs Caught</span>
                </div>
                <div class="stat">
                    <span class="stat-value">100%</span>
                    <span class="stat-label">Tracking</span>
                </div>
                <div class="stat">
                    <span class="stat-value">0</span>
                    <span class="stat-label">Bugs Escape</span>
                </div>
            </div>
        </div>
    </div>

    <section class="features" id="features">
        <div class="features-container">
            <div class="section-title">
                <h2>Why Choose BugCatcher?</h2>
                <p>Everything you need to manage your project issues efficiently</p>
            </div>
            <div class="features-grid">
                <div class="feature">
                    <div class="feature-icon">📝</div>
                    <h3>Create Issues</h3>
                    <p>Report bugs and track them with detailed descriptions, labels, and status updates. Never lose track of a bug again.</p>
                </div>
                <div class="feature">
                    <div class="feature-icon">🏷️</div>
                    <h3>Organize with Labels</h3>
                    <p>Categorize issues using custom labels for better organization. Filter and sort to find exactly what you need.</p>
                </div>
                <div class="feature">
                    <div class="feature-icon">👥</div>
                    <h3>Team Collaboration</h3>
                    <p>Assign issues to team members and track who is working on what. Perfect for agile development teams.</p>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <p>&copy; 2026 BugCatcher. Built for students, by students.</p>
    </footer>

    <script>
        // Animation enhancements
        const catcherContainer = document.getElementById('catcherContainer');
        const bug = document.getElementById('bug');
        const caughtIndicator = document.getElementById('caughtIndicator');
        const speechBubble = document.getElementById('speechBubble');
        const animationContainer = document.querySelector('.animation-container');
        
        // Track catcher direction based on animation time
        // Animation cycle: 8000ms
        // Facing LEFT from 44% to 92% (3520ms to 7360ms)
        function isCatcherFacingLeft() {
            const cycleStart = Date.now() % 8000; // Current position in 8s cycle
            // Facing left between ~44% and 92% of the cycle
            return cycleStart >= 3520 && cycleStart <= 7360;
        }

        // Sync the catch moment with animation
        function triggerCatchMoment() {
            // Bug panic mode - starts panicking before getting caught
            setTimeout(() => {
                bug.classList.add('panic');
            }, 3500);

            // Victory moment - bug gets caught
            setTimeout(() => {
                bug.classList.remove('panic');
                bug.classList.add('caught');
                catcherContainer.classList.add('victory');
                caughtIndicator.classList.add('show');
                
                // Position and show speech bubble above catcher
                const catcherRect = catcherContainer.getBoundingClientRect();
                const containerRect = animationContainer.getBoundingClientRect();
                const catcherCenter = (catcherRect.left - containerRect.left) + (catcherRect.width / 2);
                const facingLeft = isCatcherFacingLeft();
                speechBubble.style.left = catcherCenter + 'px';
                speechBubble.style.transform = facingLeft 
                    ? 'translateX(-50%) scaleX(-1) scale(1)' 
                    : 'translateX(-50%) scale(1)';
                speechBubble.classList.add('show');
                
                // Add screen shake effect
                animationContainer.classList.add('shake');
                setTimeout(() => {
                    animationContainer.classList.remove('shake');
                }, 400);
                
                // Play a little particle burst effect
                createSparkles();
            }, 4800);

            // Reset for next loop
            setTimeout(() => {
                bug.classList.remove('caught');
                catcherContainer.classList.remove('victory');
                caughtIndicator.classList.remove('show');
                speechBubble.classList.remove('show');
                speechBubble.style.transform = 'translateX(-50%) scale(0)';
            }, 7000);
        }

        // Create sparkle effect when bug is caught
        function createSparkles() {
            const colors = ['#2da44e', '#3fb950', '#ffd700', '#ff6b6b', '#4ecdc4'];
            const rect = bug.getBoundingClientRect();
            const containerRect = animationContainer.getBoundingClientRect();
            
            for (let i = 0; i < 12; i++) {
                const sparkle = document.createElement('div');
                sparkle.style.cssText = `
                    position: absolute;
                    width: ${Math.random() * 8 + 4}px;
                    height: ${Math.random() * 8 + 4}px;
                    background: ${colors[Math.floor(Math.random() * colors.length)]};
                    border-radius: 50%;
                    left: ${parseFloat(bug.style.left || 450) + Math.random() * 40 - 20}px;
                    bottom: 75px;
                    pointer-events: none;
                    z-index: 30;
                `;
                
                animationContainer.appendChild(sparkle);
                
                // Animate each sparkle
                const angle = (Math.PI * 2 * i) / 12;
                const velocity = Math.random() * 60 + 40;
                const vx = Math.cos(angle) * velocity;
                const vy = Math.sin(angle) * velocity - 30;
                
                sparkle.animate([
                    { transform: 'translate(0, 0) scale(1)', opacity: 1 },
                    { transform: `translate(${vx}px, ${vy}px) scale(0)`, opacity: 0 }
                ], {
                    duration: 800,
                    easing: 'cubic-bezier(0.25, 0.46, 0.45, 0.94)'
                }).onfinish = () => sparkle.remove();
            }
        }

        // Start the animation loop
        triggerCatchMoment();
        setInterval(triggerCatchMoment, 8000);

        // Add hover effect to bug - makes it run faster and scared
        bug.addEventListener('mouseenter', () => {
            bug.style.animationDuration = '1.5s';
            bug.classList.add('panic');
            bug.style.filter = 'drop-shadow(0 4px 8px rgba(0,0,0,0.4)) brightness(1.2)';
        });

        bug.addEventListener('mouseleave', () => {
            bug.style.animationDuration = '8s';
            bug.classList.remove('panic');
            bug.style.filter = 'drop-shadow(0 4px 8px rgba(0,0,0,0.4))';
        });

        // Click to catch the bug manually
        bug.addEventListener('click', () => {
            bug.classList.add('caught');
            catcherContainer.classList.add('victory');
            caughtIndicator.classList.add('show');
            
            // Position and show speech bubble
            const catcherRect = catcherContainer.getBoundingClientRect();
            const containerRect = animationContainer.getBoundingClientRect();
            const catcherCenter = (catcherRect.left - containerRect.left) + (catcherRect.width / 2);
            const facingLeft = isCatcherFacingLeft();
            speechBubble.style.left = catcherCenter + 'px';
            speechBubble.style.transform = facingLeft 
                ? 'translateX(-50%) scaleX(-1) scale(1)' 
                : 'translateX(-50%) scale(1)';
            speechBubble.classList.add('show');
            
            createSparkles();
            
            setTimeout(() => {
                bug.classList.remove('caught');
                catcherContainer.classList.remove('victory');
                caughtIndicator.classList.remove('show');
                speechBubble.classList.remove('show');
                speechBubble.style.transform = 'translateX(-50%) scale(0)';
            }, 2000);
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add parallax effect on mouse move
        document.addEventListener('mousemove', (e) => {
            const x = (e.clientX / window.innerWidth - 0.5) * 10;
            const y = (e.clientY / window.innerHeight - 0.5) * 10;
            
            animationContainer.style.transform = `perspective(1000px) rotateY(${x * 0.2}deg) rotateX(${-y * 0.2}deg)`;
        });
    </script>
</body>
</html>
