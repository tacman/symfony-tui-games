<?php

namespace App\Park;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\QuitableTrait;
use Symfony\Component\Tui\Widget\ScheduledTickTrait;
use Symfony\Component\Tui\Widget\WidgetContext;

/**
 * Full-screen park management widget.
 *
 * Layout (inner visible widths):
 *   map area  : MAP_COLS * 2 = 40 cols
 *   separator : 1 col
 *   info panel: INFO_W = 22 cols
 *   total     : INNER_W = 63 cols
 *   height    : MAP_ROWS (14) + separator (1) + event (1) + hint (1) = 17 rows
 *
 * The outer border (╭╮╯╰) is provided by the StyleSheet in ParkCommand.
 */
class ParkWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;
    use QuitableTrait;
    use ScheduledTickTrait;

    /** Width of the info panel content (no border). */
    public const INFO_W = 22;

    /** Total inner content width: map (MAP_COLS*2) + separator (1) + info (INFO_W). */
    public const INNER_W = ParkGame::MAP_COLS * 2 + 1 + self::INFO_W;

    private readonly Style $styleBold;
    private readonly Style $styleDim;
    private readonly Style $styleMoney;
    private readonly Style $styleVisitors;
    private readonly Style $styleHappyGood;
    private readonly Style $styleHappyMid;
    private readonly Style $styleHappyBad;
    private readonly Style $styleRevenue;
    private readonly Style $styleSelected;
    private readonly Style $styleError;
    private readonly Style $styleCursorBuild;
    private readonly Style $styleCursorDemolish;
    private readonly Style $styleVisitor;

    /** @var array<string, Style> */
    private readonly array $tileStyles;

    public function __construct(private readonly ParkGame $game)
    {
        $this->styleBold = new Style(bold: true);
        $this->styleDim = new Style(dim: true);
        $this->styleMoney = new Style(color: 'bright_yellow');
        $this->styleVisitors = new Style(color: 'bright_cyan');
        $this->styleHappyGood = new Style(color: 'green');
        $this->styleHappyMid = new Style(color: 'yellow');
        $this->styleHappyBad = new Style(color: 'red');
        $this->styleRevenue = new Style(color: 'green');
        $this->styleSelected = new Style(color: 'bright_green', bold: true);
        $this->styleError = new Style(color: 'bright_red');
        $this->styleCursorBuild = new Style(background: 'white', color: 'black');
        $this->styleCursorDemolish = new Style(background: 'red', color: 'bright_white');
        $this->styleVisitor = new Style(color: 'bright_white', bold: true);

        $tileStyles = [];
        foreach (TileType::cases() as $tile) {
            $tileStyles[$tile->name] = match ($tile) {
                TileType::Grass => new Style(color: 'green'),
                TileType::Path => new Style(color: 'bright_black'),
                TileType::Entrance => new Style(color: 'bright_yellow', bold: true),
                TileType::Coaster => new Style(color: 'bright_blue', bold: true),
                TileType::FoodStall => new Style(color: 'yellow', bold: true),
                TileType::Toilet => new Style(color: 'bright_cyan', bold: true),
            };
        }
        $this->tileStyles = $tileStyles;
    }

    // -------------------------------------------------------------------------
    // FocusableInterface
    // -------------------------------------------------------------------------

    protected static function getDefaultKeybindings(): array
    {
        return [
            'cursor_up' => [Key::UP,    'w'],
            'cursor_down' => [Key::DOWN,  's'],
            'cursor_left' => [Key::LEFT,  'a'],
            'cursor_right' => [Key::RIGHT, 'd'],
            'build' => [Key::ENTER, 'e'],
            'demolish' => ['x'],
            'mode_path' => ['1'],
            'mode_coaster' => ['2'],
            'mode_food' => ['3'],
            'mode_toilet' => ['4'],
            'mode_demolish' => ['D', 'd'],
            'pause' => ['p', Key::SPACE],
            'quit' => [Key::ctrl('c'), 'q'],
        ];
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'cursor_up')) {
            $this->game->moveCursor(0, -1);
        } elseif ($kb->matches($data, 'cursor_down')) {
            $this->game->moveCursor(0, 1);
        } elseif ($kb->matches($data, 'cursor_left')) {
            $this->game->moveCursor(-1, 0);
        } elseif ($kb->matches($data, 'cursor_right')) {
            $this->game->moveCursor(1, 0);
        } elseif ($kb->matches($data, 'build')) {
            $this->game->build();
        } elseif ($kb->matches($data, 'demolish')) {
            $this->game->demolish();
        } elseif ($kb->matches($data, 'mode_path')) {
            $this->game->setBuildMode(BuildMode::Path);
        } elseif ($kb->matches($data, 'mode_coaster')) {
            $this->game->setBuildMode(BuildMode::Coaster);
        } elseif ($kb->matches($data, 'mode_food')) {
            $this->game->setBuildMode(BuildMode::FoodStall);
        } elseif ($kb->matches($data, 'mode_toilet')) {
            $this->game->setBuildMode(BuildMode::Toilet);
        } elseif ($kb->matches($data, 'mode_demolish')) {
            $this->game->setBuildMode(BuildMode::Demolish);
        } elseif ($kb->matches($data, 'pause')) {
            $this->game->togglePause();
        } elseif ($kb->matches($data, 'quit')) {
            $this->dispatchQuit();

            return;
        }

        $this->invalidate();
    }

    // -------------------------------------------------------------------------
    // Scheduling
    // -------------------------------------------------------------------------

    protected function resolveScheduledTickContext(): ?WidgetContext
    {
        return $this->getContext();
    }

    protected function onScheduledTick(): void
    {
        $this->game->tick();
        $this->invalidate();
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->startScheduledTick(0.25);
    }

    protected function onDetach(): void
    {
        $this->stopScheduledTick();
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function render(RenderContext $context): array
    {
        $COLS = ParkGame::MAP_COLS;
        $ROWS = ParkGame::MAP_ROWS;
        $innerW = self::INNER_W;

        if ($context->getColumns() < $innerW) {
            return [$this->styleError->apply("Terminal too small! ({$innerW} columns minimum)")];
        }

        // Visitor positions map [y][x] => count
        $vmap = [];
        foreach ($this->game->getVisitors() as $v) {
            $vmap[$v->y][$v->x] = ($vmap[$v->y][$v->x] ?? 0) + 1;
        }

        $infoLines = $this->buildInfoPanel();
        $cx = $this->game->getCursorX();
        $cy = $this->game->getCursorY();

        $lines = [];

        // MAP_ROWS content rows: map (40) + separator (1) + info (22) = 63
        for ($y = 0; $y < $ROWS; ++$y) {
            $row = '';
            for ($x = 0; $x < $COLS; ++$x) {
                $tile = $this->game->getTileAt($x, $y);
                $isCursor = ($x === $cx && $y === $cy);
                $vCount = $vmap[$y][$x] ?? 0;
                $row .= $this->renderCell($tile, $isCursor, $vCount);
            }
            $lines[] = $row.' '.$infoLines[$y];
        }

        // Separator + two status lines — each always exactly INNER_W wide
        $lines[] = $this->styleDim->apply(str_repeat('─', $innerW));
        $lines[] = $this->buildEventLine($innerW);
        $lines[] = $this->buildHintLine($innerW);

        return $lines;
    }

    private function renderCell(TileType $tile, bool $isCursor, int $visitorCount): string
    {
        $char = $visitorCount >= 2 ? '@@' : (1 === $visitorCount ? '@ ' : $tile->chars());

        if ($isCursor) {
            $style = BuildMode::Demolish === $this->game->getBuildMode()
                ? $this->styleCursorDemolish
                : $this->styleCursorBuild;

            return $style->apply($char);
        }

        if ($visitorCount > 0) {
            return $this->styleVisitor->apply($char);
        }

        return $this->tileStyles[$tile->name]->apply($char);
    }

    /**
     * Returns exactly MAP_ROWS = 14 lines, each with visible width INFO_W = 22.
     *
     * @return string[]
     */
    private function buildInfoPanel(): array
    {
        $W = self::INFO_W;
        $game = $this->game;
        $mode = $game->getBuildMode();

        $pad = static fn (string $s): string => mb_str_pad($s, $W);

        $money = '$'.number_format($game->getMoney());
        $visitors = $game->getVisitorCount();
        $happy = $game->getAverageHappiness();
        $revenue = '$'.number_format($game->getTotalRevenue());

        $happyStyle = $happy >= 70 ? $this->styleHappyGood : ($happy >= 40 ? $this->styleHappyMid : $this->styleHappyBad);

        $pauseLabel = $game->isPaused() ? ' [PAUSE]' : '';
        $title = 'TERMINAL PARK'.$pauseLabel;
        $titleLen = mb_strlen($title);
        $leftPad = (int) (($W - $titleLen) / 2);
        $rightPad = $W - $titleLen - $leftPad;

        $lines = [];
        $lines[] = $this->styleBold->apply(str_repeat(' ', $leftPad).$title.str_repeat(' ', $rightPad)); // row 0
        $lines[] = $pad('');                                                                              // row 1
        $lines[] = $this->styleMoney->apply($pad(" \$ Money    : $money"));                              // row 2
        $lines[] = $this->styleVisitors->apply($pad(" @ Visitors : $visitors"));                         // row 3
        $lines[] = $happyStyle->apply($pad(" ~ Happiness: {$happy}%"));                                  // row 4
        $lines[] = $this->styleRevenue->apply($pad(" + Revenue  : $revenue"));                           // row 5
        $lines[] = $pad('');                                                                              // row 6
        $lines[] = $this->styleBold->apply($pad(' BUILD :'));                                             // row 7
        foreach (BuildMode::cases() as $m) {                                                             // rows 8–12
            $cost = null !== $m->cost() ? ' $'.$m->cost() : '';
            $label = " [{$m->shortKey()}] {$m->label()}";
            $plain = mb_str_pad($label, $W - mb_strlen($cost)).$cost;
            $lines[] = ($m === $mode) ? $this->styleSelected->apply($plain) : $plain;
        }
        $lines[] = $pad('');                                                                              // row 13

        // Exactly MAP_ROWS = 14 lines
        return $lines;
    }

    /**
     * Coords + last event, always padded to exactly $width visible chars.
     */
    private function buildEventLine(int $width): string
    {
        $cx = $this->game->getCursorX();
        $cy = $this->game->getCursorY();
        $tile = $this->game->getTileAt($cx, $cy);
        $event = $this->game->getLastEvent();

        $coordLabel = "({$cx},{$cy}) {$tile->label()}";
        $coordLen = AnsiUtils::visibleWidth($coordLabel);

        // Fit event message within remaining space after the "  " prefix
        $maxEventLen = max(0, $width - $coordLen - 2);
        if (AnsiUtils::visibleWidth($event) > $maxEventLen) {
            $event = AnsiUtils::truncateToWidth($event, $maxEventLen, '');
        }

        $eventText = "  {$event}";
        $usedWidth = $coordLen + AnsiUtils::visibleWidth($eventText);

        return $this->styleBold->apply($coordLabel)
            .$this->styleDim->apply($eventText)
            .str_repeat(' ', max(0, $width - $usedWidth));
    }

    /**
     * Key hints, always exactly $width visible chars wide.
     */
    private function buildHintLine(int $width): string
    {
        $hint = 'WASD/↑↓←→  E:Build  X:Demo  1-4/D:Mode  P:Pause  Q:Quit';

        return $this->styleDim->apply(mb_str_pad($hint, $width));
    }
}
