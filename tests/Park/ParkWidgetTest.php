<?php

namespace App\Tests\Park;

use App\Park\ParkGame;
use App\Park\ParkWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Align;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\BorderPattern;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Style\VerticalAlign;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

class ParkWidgetTest extends TestCase
{
    public function testRenderMatchesSnapshot(): void
    {
        // Initial state is fully deterministic: all grass + entrance tile,
        // cursor at (ENTRANCE_X, ENTRANCE_Y-1), no visitors, Path build mode.
        $game = new ParkGame(startingMoney: 2000);

        $stylesheet = new StyleSheet([
            ':root' => new Style(
                align: Align::Center,
                verticalAlign: VerticalAlign::Center,
            ),
            ParkWidget::class => new Style(
                maxColumns: ParkWidget::INNER_W + 2,
                border: Border::from([1], BorderPattern::ROUNDED, 'yellow'),
                dim: true,
            ),
            ParkWidget::class.':focus' => new Style(
                border: Border::from([1], BorderPattern::ROUNDED, 'bright_yellow'),
                dim: false,
            ),
        ]);

        // Terminal wide/tall enough to center the 65×19 widget.
        $terminal = new VirtualTerminal(90, 25);
        $tui = new Tui($stylesheet, terminal: $terminal);

        $widget = new ParkWidget($game);
        $tui->add($widget);
        $tui->setFocus($widget);
        $tui->start();
        $tui->processRender();

        $plain = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        $snapshotFile = __DIR__.'/snapshots/render.txt';
        if (!file_exists($snapshotFile) || getenv('UPDATE_SNAPSHOTS')) {
            if (!is_dir(\dirname($snapshotFile))) {
                mkdir(\dirname($snapshotFile), 0755, true);
            }
            file_put_contents($snapshotFile, $plain);
        }

        $this->assertStringEqualsFile($snapshotFile, $plain);
    }
}
