<?php

namespace App\Events;

use App\Models\Game;
use App\Models\Move;
use App\Models\Player;
use Thunk\Verbs\Event;
use App\Events\GameEnded;
use App\States\GameState;
use App\States\PlayerState;
use Thunk\Verbs\Attributes\Autodiscovery\StateId;

class PlayerPlayedTile extends Event
{
    #[StateId(GameState::class)]
    public int $game_id;

    #[StateId(PlayerState::class)]
    public int $player_id;

    public int $space;

    public string $direction;

    public ?array $bot_move_scores = null;

    public array $board_before_slide;

    public function authorize()
    {
        $game = $this->state(GameState::class);

        $player = $this->state(PlayerState::class);

        $this->assert(
            $game->current_player_id === $this->player_id,
            'It is not this player '.$this->player_id.' turn'
        );

        $this->assert(
            $game->phase === $game::PHASE_PLACE_TILE,
            'It is time to move the elephant, not play a tile'
        );

        $this->assert(
            $player->hand > 0,
            'Player '.$this->player_id.' has no tiles'
        );

        $this->assert(
            $game->player_1_id === $this->player_id || $game->player_2_id === $this->player_id,
            'Player '.$this->player_id.' is not in this game'
        );

        $this->assert(
            $game->status === 'active',
            'Game '.$this->game_id.' is not active'
        );
    }

    public function validate()
    {
        $this->assert(
            collect([1, 2, 3, 4, 5, 8, 9, 12, 13, 14, 15, 16])->contains($this->space),
            'Invalid space'
        );

        $this->assert(
            collect(['up', 'down', 'left', 'right'])->contains($this->direction),
            'Invalid direction'
        );

        $this->assert(
            $this->state(GameState::class)->slideIsBlockedByElephant($this->space, $this->direction) === false,
            'Elephant blocks this slide.'
        );
    }

    public function applyToPlayer(PlayerState $state)
    {
        //
    }

    public function applyToGame(GameState $state)
    {
        $this->state(PlayerState::class)->hand--;

        $state->moves[] = [
            'type' => 'tile',
            'player_id' => $this->player_id,
            'space' => $this->space,
            'direction' => $this->direction,
        ];

        $sliding_positions = $state->slidingPositions($this->space, $this->direction);

        $occupants = $state->slidingPositionOccupants($this->space, $this->direction);

        if ($occupants[3] && $occupants[2] && $occupants[1] && $occupants[0]) {
            PlayerState::load($occupants[3])->hand++;
        }

        if ($occupants[2] && $occupants[1] && $occupants[0]) {
            $state->board[$sliding_positions[3]] = $occupants[2];
        }

        if ($occupants[1] && $occupants[0]) {
            $state->board[$sliding_positions[2]] = $occupants[1];
        }

        if ($occupants[0]) {
            $state->board[$sliding_positions[1]] = $occupants[0];
        }

        $state->board[$this->space] = (string) $this->player_id;

        $state->phase = GameState::PHASE_MOVE_ELEPHANT;

        $both_player_hands_are_empty = $state->players()
            ->map(fn ($player) => $player->hand)
            ->sum() === 0;

        if (count($state->victors($state->board)) > 0 || $both_player_hands_are_empty) {
            $state->status = 'complete';
            $state->victor_ids = $state->victors($state->board);
        }
    }

    public function fired()
    {
        if ($this->state(GameState::class)->status === 'complete') {
            GameEnded::fire(game_id: $this->game_id);
        }
    }

    public function handle()
    {
        $game = $this->state(GameState::class);

        $game_model = Game::find($this->game_id);
        
        $game_model->update([
            'status' => $game->status,
            'board' => $game->board,
            'valid_elephant_moves' => $game->validElephantMoves(),
            'valid_slides' => $game->validSlides(),
            'phase' => $game->phase,
            'current_player_id' => $game->current_player_id,
            'victor_ids' => $game->victor_ids,
        ]);

        Player::find($game->current_player_id)->update([
            'hand' => PlayerState::load($game->current_player_id)->hand,
        ]);

        $move = Move::create([
            'game_id' => $this->game_id,
            'player_id' => $this->player_id,
            'type' => 'tile',
            'board_before' => $this->board_before_slide,
            'board_after' => $game->board,
            'elephant_before' => $game->elephant_space,
            'elephant_after' => $game->elephant_space,
            'bot_move_scores' => $this->bot_move_scores,
            'initial_slide' => ['space' => $this->space, 'direction' => $this->direction],
        ]);

        PlayerPlayedTileBroadcast::dispatch($game_model, $move);
    }
}
