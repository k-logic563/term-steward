<?php
/**
 * Phase 4 planning controls and preview output.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Admin;

use TermSteward\Domain\Operation\Action;
use TermSteward\Domain\Operation\Status;
use TermSteward\Domain\Operation\Taxonomy;
use TermSteward\Application\Planning\PlanErrorCode;
use WP_Term;

/**
 * Renders operation planning state without changing taxonomy data.
 */
final class PlanningPanel {
	/**
	 * Renders the selectable list, processing panel, plan, and preview.
	 *
	 * @param Taxonomy             $taxonomy  Current taxonomy.
	 * @param string               $type_label Translated taxonomy label.
	 * @param array<string, mixed> $inventory Current inventory page.
	 * @param array<string, mixed> $state     Request state.
	 * @param callable             $render_pagination Renders controls around the inventory table.
	 * @param callable             $render_sort_header Renders one sortable column heading.
	 */
	public function render( Taxonomy $taxonomy, string $type_label, array $inventory, array $state, callable $render_pagination, callable $render_sort_header ): void {
		$operation    = is_array( $state['operation'] ) ? $state['operation'] : null;
		$plan         = is_array( $operation['requested_data']['plan'] ?? null ) ? $operation['requested_data']['plan'] : array();
		$errors       = is_array( $state['errors'] ) ? $state['errors'] : array();
		$field_errors = is_array( $state['field_errors'] ?? null ) ? $state['field_errors'] : array();
		$selected     = is_array( $state['selected_ids'] ) ? array_map( 'intval', $state['selected_ids'] ) : array();
		$input        = is_array( $state['input'] ) ? $state['input'] : array();

		$this->render_notice( $state['notice'] ?? null, $errors );
		?>
		<form id="term-steward-planning-form" class="term-steward-planning-form" method="post">
			<?php wp_nonce_field( PlanController::NONCE_ACTION, PlanController::NONCE_FIELD ); ?>
			<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy->value ); ?>">
			<?php if ( null === $operation || in_array( $operation['status'], array( Status::DRAFT->value, Status::PREVIEWED->value ), true ) ) : ?>
				<?php $this->render_process_panel( $taxonomy, $inventory, $selected, $plan, $input, $errors, $field_errors ); ?>
			<?php endif; ?>
			<?php $this->render_table( $taxonomy, $type_label, $inventory, $selected, $render_pagination, $render_sort_header ); ?>
			<?php // The shared operation-plan tab owns saved-plan review and preview. ?>
			<?php if ( null !== $operation && in_array( $operation['status'], array( Status::RUNNING->value, Status::COMPLETED->value, Status::PARTIAL_FAILED->value, Status::FAILED->value ), true ) ) : ?>
				<?php $this->render_execution( $operation ); ?>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Renders selection controls with accessible term labels.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param string               $type_label Translated taxonomy label.
	 * @param array<string, mixed> $inventory Current inventory page.
	 * @param array                $selected Selected term IDs after an error.
	 * @param callable             $render_pagination Renders table navigation.
	 * @param callable             $render_sort_header Renders one sortable column heading.
	 */
	private function render_table( Taxonomy $taxonomy, string $type_label, array $inventory, array $selected, callable $render_pagination, callable $render_sort_header ): void {
		$default_category = (int) get_option( 'default_category' );
		$parent_ids       = array();
		if ( Taxonomy::CATEGORY === $taxonomy ) {
			$term_parents = get_terms(
				array(
					'taxonomy'   => $taxonomy->value,
					'hide_empty' => false,
					'fields'     => 'id=>parent',
				)
			);
			if ( is_array( $term_parents ) ) {
				$parent_ids = array_map( 'intval', array_values( $term_parents ) );
			}
		}
		?>
		<?php $render_pagination( 'top' ); ?>
		<div class="term-steward-table-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr__( 'Category and tag list', 'term-steward' ); ?>">
		<table class="wp-list-table widefat fixed striped term-steward-inventory-table">
			<thead><tr>
				<td class="manage-column check-column"><input type="checkbox" class="term-steward-select-page" aria-label="<?php echo esc_attr__( 'Select all terms on this page', 'term-steward' ); ?>"></td>
				<?php $render_sort_header( 'name', __( 'Name', 'term-steward' ) ); ?>
				<th scope="col"><?php echo esc_html__( 'Slug', 'term-steward' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Type', 'term-steward' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Parent category', 'term-steward' ); ?></th>
				<?php $render_sort_header( 'published_count', __( 'Published posts', 'term-steward' ) ); ?>
				<th scope="col"><?php echo esc_html__( 'Total relationships', 'term-steward' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Usage', 'term-steward' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( array() === $inventory['items'] ) : ?>
				<tr><td class="term-steward-inventory-table__empty" colspan="8"><?php echo esc_html__( 'No terms found.', 'term-steward' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $inventory['items'] as $term ) : ?>
					<?php $deletion_available = 0 === (int) $term['total_relationship_count'] && ! ( Taxonomy::CATEGORY === $taxonomy && $default_category === (int) $term['term_id'] ); ?>
					<?php $has_children = in_array( (int) $term['term_id'], $parent_ids, true ); ?>
					<?php $excluded_use = (int) $term['total_relationship_count'] > (int) $term['published_post_count']; ?>
					<tr data-term-key="<?php echo esc_attr( (string) $term['term_id'] ); ?>" data-term-name="<?php echo esc_attr( (string) $term['name'] ); ?>" data-term-slug="<?php echo esc_attr( (string) $term['slug'] ); ?>" data-published-count="<?php echo esc_attr( (string) $term['published_post_count'] ); ?>" data-total-count="<?php echo esc_attr( (string) $term['total_relationship_count'] ); ?>" data-deletion-available="<?php echo $deletion_available ? '1' : '0'; ?>" data-merge-retained="<?php echo $excluded_use || $has_children ? '1' : '0'; ?>" data-excluded-use="<?php echo $excluded_use ? '1' : '0'; ?>" data-has-children="<?php echo $has_children ? '1' : '0'; ?>" data-default-category="<?php echo Taxonomy::CATEGORY === $taxonomy && $default_category === (int) $term['term_id'] ? '1' : '0'; ?>">
						<th scope="row" class="check-column">
							<label class="screen-reader-text" for="term-steward-term-<?php echo esc_attr( (string) $term['term_id'] ); ?>">
								<?php
								printf(
									/* translators: %s: taxonomy term name. */
									esc_html__( 'Select %s', 'term-steward' ),
									esc_html( (string) $term['name'] )
								);
								?>
							</label>
							<input id="term-steward-term-<?php echo esc_attr( (string) $term['term_id'] ); ?>" class="term-steward-term-select" type="checkbox" name="selected_terms[]" value="<?php echo esc_attr( (string) $term['term_id'] ); ?>" <?php checked( in_array( (int) $term['term_id'], $selected, true ) ); ?>>
							<input type="hidden" name="term_taxonomy_ids[<?php echo esc_attr( (string) $term['term_id'] ); ?>]" value="<?php echo esc_attr( (string) $term['term_taxonomy_id'] ); ?>">
						</th>
						<td data-label="<?php echo esc_attr__( 'Name', 'term-steward' ); ?>"><strong><?php echo esc_html( (string) $term['name'] ); ?></strong></td>
						<td data-label="<?php echo esc_attr__( 'Slug', 'term-steward' ); ?>"><code><?php echo esc_html( (string) $term['slug'] ); ?></code></td>
						<td data-label="<?php echo esc_attr__( 'Type', 'term-steward' ); ?>"><?php echo esc_html( $type_label ); ?></td>
						<td data-label="<?php echo esc_attr__( 'Parent category', 'term-steward' ); ?>"><?php echo esc_html( $this->parent_label( $taxonomy, $term['parent_name'] ) ); ?></td>
						<td class="term-steward-number" data-label="<?php echo esc_attr__( 'Published posts', 'term-steward' ); ?>"><?php echo esc_html( number_format_i18n( (int) $term['published_post_count'] ) ); ?></td>
						<td class="term-steward-number" data-label="<?php echo esc_attr__( 'Total relationships', 'term-steward' ); ?>"><?php echo esc_html( number_format_i18n( (int) $term['total_relationship_count'] ) ); ?></td>
						<td data-label="<?php echo esc_attr__( 'Usage', 'term-steward' ); ?>"><?php echo esc_html( $this->usage_label( (string) $term['usage'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		</div>
		<?php $render_pagination( 'bottom' ); ?>
		<?php
	}

	/**
	 * Renders grouped operation inputs; errors are the only automatic-open reason.
	 *
	 * @param Taxonomy                    $taxonomy Current taxonomy.
	 * @param array<string, mixed>        $inventory Current inventory page.
	 * @param array                       $selected Selected term IDs.
	 * @param list<array<string, mixed>>  $plan Saved normalized plan.
	 * @param array<string, mixed>        $input Redisplayed sanitized input.
	 * @param array                       $errors Validation errors.
	 * @param array<string, list<string>> $field_errors Validation errors grouped by field.
	 */
	private function render_process_panel( Taxonomy $taxonomy, array $inventory, array $selected, array $plan, array $input, array $errors, array $field_errors ): void {
		$action              = is_string( $input['operation_action'] ?? null ) ? $input['operation_action'] : '';
		$focus               = $this->error_focus( $field_errors );
		$items               = $this->selected_items( $inventory, $selected );
		$destination_removed = Action::MERGE->value === $action && $this->destination_is_selected_source( (string) ( $input['destination'] ?? '' ), $selected );
		if ( $destination_removed ) {
			$input['destination'] = '';
		}
		/* translators: %d: number of selected terms. */
		$selected_format = __( '%d terms selected', 'term-steward' );
		?>
		<details class="term-steward-panel term-steward-process-panel" <?php echo array() !== $errors ? 'open' : ''; ?>>
			<summary class="term-steward-panel__summary" aria-expanded="<?php echo array() !== $errors ? 'true' : 'false'; ?>">
				<span class="term-steward-panel__heading"><span class="term-steward-panel__icon" aria-hidden="true"></span><span><?php echo esc_html__( 'Action panel', 'term-steward' ); ?></span></span>
				<span class="term-steward-filter-summary" aria-live="polite">
					<span class="term-steward-selection-summary" data-none="<?php echo esc_attr__( 'No terms selected', 'term-steward' ); ?>" data-selected="<?php echo esc_attr( $selected_format ); ?>">
						<?php echo esc_html( $this->selected_count_label( count( $selected ) ) ); ?>
					</span>
					<span><?php echo esc_html( $this->plan_count_label( count( $plan ) ) ); ?></span>
				</span>
			</summary>
			<div class="term-steward-process-content">
				<section id="term-steward-selection-section" class="term-steward-process-group" aria-labelledby="term-steward-target-heading">
					<h3 id="term-steward-target-heading"><?php echo esc_html__( 'Selected targets', 'term-steward' ); ?></h3>
					<p class="term-steward-selected-count" aria-live="polite"><?php echo esc_html( $this->selected_count_label( count( $selected ) ) ); ?></p>
					<div class="term-steward-selected-terms" aria-live="polite" data-selection-error="<?php echo isset( $field_errors['selection'] ) ? '1' : '0'; ?>" data-empty="<?php echo esc_attr__( 'Select a category or tag to process from the list.', 'term-steward' ); ?>" data-more="<?php /* translators: %d: number of additional selected terms. */ echo esc_attr__( '%d more', 'term-steward' ); ?>">
						<?php if ( ! isset( $field_errors['selection'] ) || array() !== $items ) : ?>
							<?php $this->render_selected_targets( $items ); ?>
						<?php endif; ?>
					</div>
					<?php if ( Action::MERGE->value !== $action ) : ?>
						<?php $this->render_field_errors( $field_errors, 'selection', 'term-steward-selection-error' ); ?>
					<?php endif; ?>
				</section>

				<section class="term-steward-process-group" aria-labelledby="term-steward-operation-heading">
					<h3 id="term-steward-operation-heading"><?php echo esc_html__( 'Action method', 'term-steward' ); ?></h3>
					<fieldset class="term-steward-operation-choices" <?php echo isset( $field_errors['operation'] ) ? 'aria-invalid="true" aria-describedby="' . esc_attr( $this->field_error_ids( $field_errors, 'operation', 'term-steward-operation-error' ) ) . '"' : ''; ?>>
						<legend class="screen-reader-text"><?php echo esc_html__( 'Action method', 'term-steward' ); ?></legend>
						<?php $this->render_action_choice( Action::RENAME, __( 'Rename', 'term-steward' ), __( 'Change the name and, if needed, the slug.', 'term-steward' ), $action, 'operation' === $focus ); ?>
						<?php $this->render_action_choice( Action::MERGE, __( 'Merge', 'term-steward' ), __( 'Move published-post assignments into an existing term.', 'term-steward' ), $action, false ); ?>
						<?php $this->render_action_choice( Action::DELETE, __( 'Delete', 'term-steward' ), __( 'Make a globally unused term a deletion target.', 'term-steward' ), $action, false ); ?>
						<?php $this->render_field_errors( $field_errors, 'operation', 'term-steward-operation-error' ); ?>
					</fieldset>
				</section>

				<section class="term-steward-process-group term-steward-changes-section" aria-labelledby="term-steward-change-heading" <?php echo null === Action::tryFrom( $action ) ? 'hidden' : ''; ?>>
					<h3 id="term-steward-change-heading"><?php echo esc_html__( 'Changes', 'term-steward' ); ?></h3>
					<div class="term-steward-action-fields" data-action-fields="rename" <?php echo Action::RENAME->value !== $action ? 'hidden' : ''; ?>>
						<div class="term-steward-related-fields">
							<div class="term-steward-field-group term-steward-readonly-field"><span class="term-steward-field-label"><?php echo esc_html__( 'Current name', 'term-steward' ); ?></span><span class="term-steward-field-display term-steward-current-name" data-fallback="<?php echo esc_attr__( 'Select one term.', 'term-steward' ); ?>"><?php echo esc_html( $this->single_selected_value( $items, 'name' ) ); ?></span></div>
							<div class="term-steward-field-group"><label class="term-steward-field-label" for="term-steward-new-name"><?php echo esc_html__( 'New name', 'term-steward' ); ?></label><input class="term-steward-field-control tt-control" id="term-steward-new-name" type="text" name="new_name" value="<?php echo esc_attr( (string) ( $input['new_name'] ?? '' ) ); ?>" <?php echo isset( $field_errors['new_name'] ) ? 'aria-invalid="true" aria-describedby="' . esc_attr( $this->field_error_ids( $field_errors, 'new_name', 'term-steward-new-name-error' ) ) . '"' : ''; ?> <?php echo 'new_name' === $focus ? 'data-error-focus="true"' : ''; ?>><?php $this->render_field_errors( $field_errors, 'new_name', 'term-steward-new-name-error' ); ?></div>
						</div>
						<div class="term-steward-related-fields">
							<div class="term-steward-field-group term-steward-readonly-field"><span class="term-steward-field-label"><?php echo esc_html__( 'Current slug', 'term-steward' ); ?></span><span class="term-steward-field-display term-steward-current-slug" data-fallback="<?php echo esc_attr__( 'Select one term.', 'term-steward' ); ?>"><?php echo esc_html( $this->single_selected_value( $items, 'slug' ) ); ?></span></div>
							<div class="term-steward-field-group"><label class="term-steward-field-label" for="term-steward-new-slug"><?php echo esc_html__( 'New slug (optional)', 'term-steward' ); ?></label><input class="term-steward-field-control tt-control" id="term-steward-new-slug" type="text" name="new_slug" value="<?php echo esc_attr( (string) ( $input['new_slug'] ?? '' ) ); ?>" aria-describedby="term-steward-new-slug-help<?php echo isset( $field_errors['new_slug'] ) ? ' ' . esc_attr( $this->field_error_ids( $field_errors, 'new_slug', 'term-steward-new-slug-error' ) ) : ''; ?>" <?php echo isset( $field_errors['new_slug'] ) ? 'aria-invalid="true"' : ''; ?> <?php echo 'new_slug' === $focus ? 'data-error-focus="true"' : ''; ?>><p id="term-steward-new-slug-help" class="description term-steward-field-help"><?php echo esc_html__( 'Leave blank to keep the current slug.', 'term-steward' ); ?></p><?php $this->render_field_errors( $field_errors, 'new_slug', 'term-steward-new-slug-error' ); ?></div>
						</div>
					</div>

					<div class="term-steward-action-fields" data-action-fields="merge" <?php echo Action::MERGE->value !== $action ? 'hidden' : ''; ?>>
						<div id="term-steward-merge-source-group" class="term-steward-field-group term-steward-readonly-field" <?php echo Action::MERGE->value === $action && isset( $field_errors['selection'] ) ? 'aria-invalid="true" aria-describedby="' . esc_attr( $this->field_error_ids( $field_errors, 'selection', 'term-steward-merge-source-error' ) ) . '"' : ''; ?>>
							<span class="term-steward-field-label"><?php echo esc_html__( 'Merge sources', 'term-steward' ); ?></span>
							<span class="term-steward-field-display term-steward-merge-sources" data-empty="<?php echo esc_attr__( 'No terms selected', 'term-steward' ); ?>"><?php echo esc_html( $this->selected_name_summary( $items ) ); ?></span>
							<div class="term-steward-field-help term-steward-merge-outcome" data-heading="<?php echo esc_attr__( 'Source term outcome after merge', 'term-steward' ); ?>" data-delete="<?php echo esc_attr__( 'Planned for deletion', 'term-steward' ); ?>" data-retain="<?php echo esc_attr__( 'Planned for retention', 'term-steward' ); ?>"><?php $this->render_merge_outcomes( $taxonomy, $items ); ?></div>
							<?php if ( Action::MERGE->value === $action ) : ?>
								<?php $this->render_field_errors( $field_errors, 'selection', 'term-steward-merge-source-error' ); ?>
							<?php endif; ?>
						</div>
						<div id="term-steward-merge-destination-group" class="term-steward-field-group" data-cleared="<?php echo esc_attr__( 'The selected destination became a source, so the destination was cleared.', 'term-steward' ); ?>">
							<label class="term-steward-field-label" for="term-steward-destination"><?php echo esc_html__( 'Merge destination', 'term-steward' ); ?></label>
							<select class="term-steward-field-control tt-control" id="term-steward-destination" name="destination" aria-describedby="term-steward-destination-help term-steward-destination-selection-notice<?php echo isset( $field_errors['destination'] ) ? ' ' . esc_attr( $this->field_error_ids( $field_errors, 'destination', 'term-steward-destination-error' ) ) : ''; ?>" <?php echo isset( $field_errors['destination'] ) ? 'aria-invalid="true"' : ''; ?> <?php echo 'destination' === $focus ? 'data-error-focus="true"' : ''; ?>>
								<option value=""><?php echo esc_html__( 'Select a merge destination.', 'term-steward' ); ?></option>
								<?php $this->render_destinations( $taxonomy, $selected, (string) ( $input['destination'] ?? '' ) ); ?>
							</select>
							<p id="term-steward-destination-help" class="description term-steward-field-help"><?php echo esc_html__( 'Select an existing term in the same taxonomy.', 'term-steward' ); ?></p>
							<p id="term-steward-destination-selection-notice" class="description term-steward-field-help" role="status" <?php echo $destination_removed ? '' : 'hidden'; ?>><?php echo esc_html__( 'The selected destination became a source, so the destination was cleared.', 'term-steward' ); ?></p>
							<?php $this->render_field_errors( $field_errors, 'destination', 'term-steward-destination-error' ); ?>
						</div>
					</div>

					<div class="term-steward-action-fields" data-action-fields="delete" <?php echo Action::DELETE->value !== $action ? 'hidden' : ''; ?>>
						<div class="term-steward-field-group term-steward-readonly-field" <?php echo isset( $field_errors['delete'] ) ? 'aria-invalid="true" aria-describedby="' . esc_attr( $this->field_error_ids( $field_errors, 'delete', 'term-steward-delete-error' ) ) . '"' : ''; ?>>
							<span class="term-steward-field-label"><?php echo esc_html__( 'Deletion targets', 'term-steward' ); ?></span>
							<div class="term-steward-field-display term-steward-delete-targets" data-empty="<?php echo esc_attr__( 'No deletion targets selected.', 'term-steward' ); ?>"><?php $this->render_delete_targets( $items ); ?></div>
							<?php $this->render_field_errors( $field_errors, 'delete', 'term-steward-delete-error' ); ?>
						</div>
					</div>
				</section>

				<section class="term-steward-process-group term-steward-validation" aria-labelledby="term-steward-validation-heading" data-server-errors="<?php echo isset( $field_errors['plan'] ) ? '1' : '0'; ?>" data-error="<?php echo esc_attr__( 'Error', 'term-steward' ); ?>" data-warning="<?php echo esc_attr__( 'Warning', 'term-steward' ); ?>" data-information="<?php echo esc_attr__( 'Information', 'term-steward' ); ?>" data-child-warning="<?php echo esc_attr__( 'The source has child categories and will be retained.', 'term-steward' ); ?>" data-excluded-warning="<?php echo esc_attr__( 'The source is used outside published posts and will be retained.', 'term-steward' ); ?>" data-impact="<?php /* translators: %d: published-post relationship count. */ echo esc_attr__( '%d published-post relationships are currently associated with the selection.', 'term-steward' ); ?>" <?php echo ! isset( $field_errors['plan'] ) && array() === $items ? 'hidden' : ''; ?>>
					<h3 id="term-steward-validation-heading"><?php echo esc_html__( 'Notices and validation results', 'term-steward' ); ?></h3>
					<div class="term-steward-validation-messages" aria-live="polite">
					<?php if ( isset( $field_errors['plan'] ) ) : ?>
						<?php $this->render_field_errors( $field_errors, 'plan', 'term-steward-plan-error' ); ?>
					<?php elseif ( array() !== $items ) : ?>
						<?php $this->render_selection_messages( $taxonomy, $action, $items ); ?>
					<?php endif; ?>
					</div>
				</section>

				<div class="term-steward-process-actions">
					<button type="submit" class="button button-primary tt-button tt-button--primary" name="plan_command" value="add"><?php echo esc_html__( 'Add to plan', 'term-steward' ); ?></button>
				</div>
			</div>
		</details>
		<?php
	}

	/**
	 * Renders the saved plan and an optional immutable preview.
	 *
	 * @param Taxonomy                  $taxonomy Current taxonomy.
	 * @param array<string, mixed>|null $operation Stored operation.
	 * @param array                     $plan Normalized plan.
	 */
	private function render_plan( Taxonomy $taxonomy, ?array $operation, array $plan ): void {
		if ( array() === $plan || null === $operation ) {
			return;
		}
		?>
		<section class="term-steward-plan" aria-labelledby="term-steward-plan-heading" aria-live="polite">
			<h2 id="term-steward-plan-heading"><?php echo esc_html__( 'Operation plan', 'term-steward' ); ?></h2>
			<p><?php echo esc_html__( 'To edit an item, remove it and add a corrected process.', 'term-steward' ); ?></p>
			<div class="term-steward-plan-items">
			<?php foreach ( $plan as $index => $item ) : ?>
				<article class="term-steward-plan-item">
					<h3><?php echo esc_html( $this->action_label( (string) $item['action'] ) ); ?></h3>
					<p><strong><?php echo esc_html__( 'Targets', 'term-steward' ); ?>:</strong> <?php echo esc_html( $this->plan_source_names( $taxonomy, $item ) ); ?></p>
					<p><strong><?php echo esc_html__( 'Planned change', 'term-steward' ); ?>:</strong> <?php echo esc_html( $this->planned_change( $taxonomy, $item ) ); ?></p>
					<p><strong><?php echo esc_html__( 'Current estimated scope', 'term-steward' ); ?>:</strong> <?php echo esc_html( $this->estimated_scope( $taxonomy, $item ) ); ?></p>
					<?php $draft_warnings = $this->draft_warnings( $taxonomy, $item ); ?>
					<?php if ( '' !== $draft_warnings ) : ?>
						<p class="term-steward-message term-steward-message--warning"><strong><?php echo esc_html__( 'Warning', 'term-steward' ); ?>:</strong> <?php echo esc_html( $draft_warnings ); ?></p>
					<?php endif; ?>
					<?php if ( Status::DRAFT->value === $operation['status'] ) : ?>
						<button type="submit" class="button-link-delete" name="remove_index" value="<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html__( 'Remove from plan', 'term-steward' ); ?></button>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
			</div>

			<?php if ( Status::DRAFT->value === $operation['status'] ) : ?>
				<p class="term-steward-preview-action"><button type="submit" class="button button-primary button-hero" name="plan_command" value="preview"><?php echo esc_html__( 'Review changes', 'term-steward' ); ?></button></p>
			<?php elseif ( Status::PREVIEWED->value === $operation['status'] ) : ?>
				<p class="term-steward-preview-action"><button type="button" class="button term-steward-reopen-preview"><?php echo esc_html__( 'Review changes', 'term-steward' ); ?></button></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Renders the execution progress and continuation control.
	 *
	 * @param array<string, mixed> $operation Execution record.
	 */
	private function render_execution( array $operation ): void {
		$progress = is_array( $operation['progress'] ?? null ) ? $operation['progress'] : array();
		?>
		<section class="term-steward-preview" aria-labelledby="term-steward-progress-heading" aria-live="polite">
			<h2 id="term-steward-progress-heading"><?php echo esc_html__( 'Execution progress', 'term-steward' ); ?></h2>
			<p><?php echo esc_html( $this->execution_status_label( (string) $operation['status'] ) ); ?></p>
			<ul>
				<li><?php /* translators: %d: item count. */ echo esc_html( sprintf( __( 'Total items: %d', 'term-steward' ), (int) ( $progress['total'] ?? 0 ) ) ); ?></li>
				<li><?php /* translators: %d: item count. */ echo esc_html( sprintf( __( 'Completed items: %d', 'term-steward' ), (int) ( $progress['completed'] ?? 0 ) ) ); ?></li>
				<li><?php /* translators: %d: item count. */ echo esc_html( sprintf( __( 'Pending items: %d', 'term-steward' ), (int) ( $progress['pending'] ?? 0 ) ) ); ?></li>
				<li><?php /* translators: %d: item count. */ echo esc_html( sprintf( __( 'Failed items: %d', 'term-steward' ), (int) ( $progress['failed'] ?? 0 ) ) ); ?></li>
				<li><?php /* translators: %d: item count. */ echo esc_html( sprintf( __( 'Skipped items: %d', 'term-steward' ), (int) ( $progress['skipped'] ?? 0 ) ) ); ?></li>
				<li><?php /* translators: %d: retained source-term count. */ echo esc_html( sprintf( __( 'Retained source terms: %d', 'term-steward' ), (int) ( $progress['skipped'] ?? 0 ) ) ); ?></li>
			</ul>
			<?php if ( 0 < (int) ( $progress['skipped'] ?? 0 ) ) : ?>
				<p class="term-steward-message term-steward-message--warning"><strong><?php echo esc_html__( 'Warning', 'term-steward' ); ?>:</strong> <?php echo esc_html__( 'Some source terms were retained because they were not safe to delete.', 'term-steward' ); ?></p>
			<?php endif; ?>
			<?php if ( 0 < (int) ( $progress['failed'] ?? 0 ) ) : ?>
				<p class="term-steward-message term-steward-message--error"><strong><?php echo esc_html__( 'Error', 'term-steward' ); ?>:</strong> <?php echo esc_html__( 'Some items could not be processed. No failed item is reported as completed.', 'term-steward' ); ?></p>
			<?php endif; ?>
			<?php if ( Status::RUNNING->value === $operation['status'] ) : ?>
				<input type="hidden" name="operation_id" value="<?php echo esc_attr( (string) $operation['id'] ); ?>">
				<p><button type="submit" class="button button-primary" name="plan_command" value="continue"><?php echo esc_html__( 'Continue next batch', 'term-steward' ); ?></button></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Returns a translated execution status.
	 *
	 * @param string $status Persisted operation status.
	 * @return string
	 */
	private function execution_status_label( string $status ): string {
		return match ( $status ) {
			Status::RUNNING->value => __( 'Running; more items remain.', 'term-steward' ),
			Status::COMPLETED->value => __( 'All changes completed.', 'term-steward' ),
			Status::PARTIAL_FAILED->value => __( 'Some items failed. The operation is partially complete.', 'term-steward' ),
			default => __( 'The operation failed without completing changes.', 'term-steward' ),
		};
	}

	/**
	 * Renders the persisted preview summary and per-operation effects.
	 *
	 * @param array<string, mixed> $operation Previewed operation.
	 * @param bool                 $auto_open Whether this request just created the preview.
	 */
	private function render_preview( array $operation, bool $auto_open ): void {
		$preview = $operation['requested_data']['preview'] ?? null;
		if ( ! is_array( $preview ) ) {
			return;
		}
		?>
		<div class="term-steward term-steward-modal" data-auto-open="<?php echo $auto_open ? '1' : '0'; ?>" hidden>
			<div class="term-steward-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="term-steward-preview-heading" tabindex="-1">
			<header class="term-steward-modal__header"><h2 id="term-steward-preview-heading"><?php echo esc_html__( 'Change preview', 'term-steward' ); ?></h2><button type="button" class="term-steward-modal__close" aria-label="<?php echo esc_attr__( 'Close', 'term-steward' ); ?>">&times;</button></header>
			<div class="term-steward-modal__body">
			<?php if ( false === ( $operation['preview_current'] ?? true ) ) : ?>
				<p class="term-steward-message term-steward-message--error" role="alert"><strong><?php echo esc_html__( 'Error', 'term-steward' ); ?>:</strong> <?php echo esc_html( ErrorMessages::label( PlanErrorCode::STALE_PREVIEW ) ); ?></p>
			<?php endif; ?>
			<?php foreach ( (array) ( $preview['items'] ?? array() ) as $index => $item ) : ?>
				<article class="term-steward-preview-item">
					<h3><?php echo esc_html( $this->action_label( (string) $item['action'] ) ); ?></h3>
					<p><strong><?php echo esc_html__( 'Targets', 'term-steward' ); ?>:</strong> <?php echo esc_html( implode( '、', array_column( $item['sources'], 'name' ) ) ); ?></p>
					<?php if ( Action::DELETE->value !== $item['action'] ) : ?>
						<p><strong><?php echo esc_html__( 'After change', 'term-steward' ); ?>:</strong> <?php echo esc_html( Action::MERGE->value === $item['action'] ? (string) ( $item['destination']['name'] ?? '' ) : (string) $item['new_name'] ); ?></p>
					<?php endif; ?>
					<?php if ( Action::RENAME->value === $item['action'] && null !== $item['new_slug'] && (string) ( $item['sources'][0]['slug'] ?? '' ) !== (string) $item['new_slug'] ) : ?>
						<p><strong><?php echo esc_html__( 'New slug', 'term-steward' ); ?>:</strong> <?php echo esc_html( (string) $item['new_slug'] ); ?></p>
					<?php endif; ?>
					<p><?php /* translators: %d: number of affected published posts. */ echo esc_html( sprintf( __( 'Affected published posts: %d', 'term-steward' ), count( $item['affected_posts'] ) ) ); ?></p>
					<?php if ( Action::MERGE->value === $item['action'] ) : ?>
						<?php foreach ( $item['sources'] as $source ) : ?>
							<p><?php echo esc_html( (string) $source['name'] ); ?>：<?php echo esc_html( $source['delete_source'] ? __( 'Delete after processing', 'term-steward' ) : __( 'Retain without deleting', 'term-steward' ) ); ?></p>
							<?php if ( ! $source['delete_source'] ) : ?>
								<p><?php echo esc_html__( 'Reason', 'term-steward' ); ?>：<?php echo esc_html( implode( ' ', array_map( array( $this, 'reason_label' ), $source['reasons'] ) ) ); ?></p>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php $blocking_warnings = array_diff( $item['warnings'], array( 'has_child_categories', 'used_by_excluded_objects' ) ); ?>
					<?php if ( array() !== $blocking_warnings ) : ?>
						<p class="term-steward-message term-steward-message--warning"><strong><?php echo esc_html__( 'Warning', 'term-steward' ); ?>:</strong> <?php echo esc_html( implode( ' ', array_map( array( $this, 'warning_label' ), $blocking_warnings ) ) ); ?></p>
					<?php endif; ?>
					<?php if ( array() !== $item['affected_posts'] ) : ?>
						<details class="term-steward-preview-posts" data-item="<?php echo esc_attr( (string) $index ); ?>" data-operation="<?php echo esc_attr( (string) $operation['id'] ); ?>" data-taxonomy="<?php echo esc_attr( (string) $operation['taxonomy'] ); ?>" data-error="<?php echo esc_attr__( 'Could not retrieve the target posts.', 'term-steward' ); ?>"><summary><?php /* translators: %d: number of affected published posts. */ echo esc_html( sprintf( __( 'Review target posts (%d)', 'term-steward' ), count( $item['affected_posts'] ) ) ); ?></summary><ul></ul></details>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
			</div>
			<footer class="term-steward-modal__footer"><button type="button" class="button tt-button tt-button--secondary term-steward-modal__cancel"><?php echo esc_html__( 'Cancel', 'term-steward' ); ?></button><button type="submit" form="term-steward-planning-form" class="button button-primary tt-button tt-button--primary term-steward-modal__run" name="plan_command" value="run" <?php disabled( false === ( $operation['preview_current'] ?? true ) ); ?>><?php echo esc_html__( 'Execute', 'term-steward' ); ?></button><input type="hidden" name="operation_id" form="term-steward-planning-form" value="<?php echo esc_attr( (string) $operation['id'] ); ?>"></footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a radio choice and description.
	 *
	 * @param Action $action      Action value.
	 * @param string $label       Translated label.
	 * @param string $description Translated description.
	 * @param string $current     Current action.
	 * @param bool   $autofocus   Whether JavaScript should focus this input.
	 */
	private function render_action_choice( Action $action, string $label, string $description, string $current, bool $autofocus ): void {
		$id = 'term-steward-action-' . $action->value;
		?>
		<label class="term-steward-operation-choice" for="<?php echo esc_attr( $id ); ?>">
			<input class="term-steward-operation-choice__control" id="<?php echo esc_attr( $id ); ?>" type="radio" name="operation_action" value="<?php echo esc_attr( $action->value ); ?>" <?php checked( $current, $action->value ); ?> <?php echo $autofocus ? 'data-error-focus="true"' : ''; ?>>
			<span class="term-steward-operation-choice__text"><strong class="term-steward-operation-choice__title"><?php echo esc_html( $label ); ?></strong><span class="description"><?php echo esc_html( $description ); ?></span></span>
		</label>
		<?php
	}

	/**
	 * Renders all same-taxonomy destinations independently of inventory paging.
	 *
	 * @param Taxonomy $taxonomy    Current taxonomy.
	 * @param array    $excluded    Selected source term IDs.
	 * @param string   $destination Submitted stable destination value.
	 */
	private function render_destinations( Taxonomy $taxonomy, array $excluded, string $destination ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy->value,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}
		$terms_by_id = array();
		$name_counts = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$terms_by_id[ $term->term_id ] = $term;
				$name_counts[ $term->name ]    = ( $name_counts[ $term->name ] ?? 0 ) + 1;
			}
		}
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term || in_array( $term->term_id, $excluded, true ) ) {
				continue;
			}
			$value = sprintf( '%d:%d', $term->term_id, $term->term_taxonomy_id );
			$label = Taxonomy::CATEGORY === $taxonomy && 1 < $name_counts[ $term->name ]
				? $this->category_path_label( $term, $terms_by_id )
				: $term->name;
			?>
			<option value="<?php echo esc_attr( $value ); ?>" data-term-key="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $destination, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php
		}
	}

	/**
	 * Disambiguates duplicate category names with category names only.
	 *
	 * @param WP_Term             $term        Destination category.
	 * @param array<int, WP_Term> $terms_by_id Categories indexed by stable ID.
	 */
	private function category_path_label( WP_Term $term, array $terms_by_id ): string {
		$names  = array( $term->name );
		$seen   = array( $term->term_id => true );
		$parent = (int) $term->parent;
		while ( 0 < $parent && isset( $terms_by_id[ $parent ] ) && ! isset( $seen[ $parent ] ) ) {
			$seen[ $parent ] = true;
			array_unshift( $names, $terms_by_id[ $parent ]->name );
			$parent = (int) $terms_by_id[ $parent ]->parent;
		}
		return implode( ' › ', $names );
	}

	/**
	 * Checks a destination value against selected sources by stable term ID.
	 *
	 * @param string $destination Submitted datalist value.
	 * @param array  $selected    Selected source term IDs.
	 */
	private function destination_is_selected_source( string $destination, array $selected ): bool {
		return 1 === preg_match( '/^(\d+):/', $destination, $matches ) && in_array( (int) $matches[1], $selected, true );
	}

	/**
	 * Returns selected inventory rows from the current page.
	 *
	 * @param array<string, mixed> $inventory Current inventory page.
	 * @param array                $selected  Selected term IDs.
	 * @return list<array<string, mixed>>
	 */
	private function selected_items( array $inventory, array $selected ): array {
		$items = array();
		foreach ( $inventory['items'] as $term ) {
			if ( in_array( (int) $term['term_id'], $selected, true ) ) {
				$items[] = $term;
			}
		}
		return $items;
	}

	/**
	 * Renders representative selected terms and their current counts.
	 *
	 * @param list<array<string, mixed>> $items Selected inventory rows.
	 */
	private function render_selected_targets( array $items ): void {
		if ( array() === $items ) {
			?>
			<p class="term-steward-selected-empty"><?php echo esc_html__( 'Select a category or tag to process from the list.', 'term-steward' ); ?></p>
			<?php
			return;
		}
		?>
		<ul class="term-steward-selected-list">
		<?php foreach ( array_slice( $items, 0, 5 ) as $item ) : ?>
			<li><?php echo esc_html( (string) $item['name'] ); ?></li>
		<?php endforeach; ?>
		<?php if ( 5 < count( $items ) ) : ?>
			<li><?php /* translators: %d: number of additional selected terms. */ echo esc_html( sprintf( __( '%d more', 'term-steward' ), count( $items ) - 5 ) ); ?></li>
		<?php endif; ?>
		</ul>
		<?php
	}

	/**
	 * Returns one selected term field or the translated fallback.
	 *
	 * @param list<array<string, mixed>> $items Selected inventory rows.
	 * @param string                     $key   Inventory field name.
	 * @return string
	 */
	private function single_selected_value( array $items, string $key ): string {
		return 1 === count( $items ) ? (string) $items[0][ $key ] : __( 'Select one term.', 'term-steward' );
	}

	/**
	 * Returns representative selected names.
	 *
	 * @param list<array<string, mixed>> $items Selected inventory rows.
	 * @return string
	 */
	private function selected_name_summary( array $items ): string {
		if ( array() === $items ) {
			return __( 'No terms selected', 'term-steward' );
		}
		$names = array_map(
			static fn( array $item ): string => (string) $item['name'],
			array_slice( $items, 0, 5 )
		);
		if ( 5 < count( $items ) ) {
			/* translators: %d: number of additional selected terms. */
			$names[] = sprintf( __( '%d more', 'term-steward' ), count( $items ) - 5 );
		}
		return implode( ', ', $names );
	}

	/**
	 * Renders whether each merge source is expected to be deleted or retained.
	 *
	 * @param Taxonomy                   $taxonomy Current taxonomy.
	 * @param list<array<string, mixed>> $items    Selected inventory rows.
	 */
	private function render_merge_outcomes( Taxonomy $taxonomy, array $items ): void {
		if ( array() === $items ) {
			return;
		}
		?>
		<p class="term-steward-field-label"><?php echo esc_html__( 'Source term outcome after merge', 'term-steward' ); ?></p>
		<ul class="term-steward-outcome-list">
		<?php foreach ( $items as $item ) : ?>
			<?php
			$has_children = Taxonomy::CATEGORY === $taxonomy && get_terms(
				array(
					'taxonomy'   => $taxonomy->value,
					'parent'     => (int) $item['term_id'],
					'fields'     => 'ids',
					'hide_empty' => false,
				)
			);
			$retained     = (int) $item['total_relationship_count'] > (int) $item['published_post_count'] || ( is_array( $has_children ) && array() !== $has_children );
			$reasons      = array();
			if ( (int) $item['total_relationship_count'] > (int) $item['published_post_count'] ) {
				$reasons[] = $this->warning_label( 'used_by_excluded_objects' );
			}
			if ( is_array( $has_children ) && array() !== $has_children ) {
				$reasons[] = $this->warning_label( 'has_child_categories' );
			}
			?>
			<li><?php echo esc_html( (string) $item['name'] ); ?> — <?php echo esc_html( $retained ? __( 'Planned for retention', 'term-steward' ) : __( 'Planned for deletion', 'term-steward' ) ); ?><?php echo array() !== $reasons ? ' — ' . esc_html( implode( ' ', $reasons ) ) : ''; ?></li>
		<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Renders current selection warnings, errors, and impact information.
	 *
	 * @param Taxonomy                   $taxonomy Current taxonomy.
	 * @param string                     $action   Selected action value.
	 * @param list<array<string, mixed>> $items    Selected inventory rows.
	 */
	private function render_selection_messages( Taxonomy $taxonomy, string $action, array $items ): void {
		$published = array_sum( array_map( static fn( array $item ): int => (int) $item['published_post_count'], $items ) );
		if ( Action::MERGE->value === $action ) {
			foreach ( $items as $item ) {
				if ( (int) $item['total_relationship_count'] > (int) $item['published_post_count'] ) {
					$this->render_status_message( 'warning', __( 'Warning', 'term-steward' ), $this->warning_label( 'used_by_excluded_objects' ) );
				}
				if ( Taxonomy::CATEGORY === $taxonomy && $this->has_children( $taxonomy, (int) $item['term_id'] ) ) {
					$this->render_status_message( 'warning', __( 'Warning', 'term-steward' ), $this->warning_label( 'has_child_categories' ) );
				}
			}
		}
		$this->render_status_message(
			'info',
			__( 'Information', 'term-steward' ),
			sprintf(
				/* translators: %d: published-post relationship count. */
				__( '%d published-post relationships are currently associated with the selection.', 'term-steward' ),
				$published
			)
		);
	}

	/**
	 * Renders one labelled validation-status message.
	 *
	 * @param string $type    Message type: error, warning, or info.
	 * @param string $heading Translated status heading.
	 * @param string $message Translated message text.
	 */
	private function render_status_message( string $type, string $heading, string $message ): void {
		$icon = 'info' === $type ? 'info-outline' : 'warning';
		?>
		<p class="term-steward-message term-steward-message--<?php echo esc_attr( $type ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><strong><?php echo esc_html( $heading ); ?>:</strong> <?php echo esc_html( $message ); ?></p>
		<?php
	}

	/**
	 * Reports whether a category currently has child categories.
	 *
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param int      $term_id  Parent term ID.
	 */
	private function has_children( Taxonomy $taxonomy, int $term_id ): bool {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy->value,
				'parent'     => $term_id,
				'fields'     => 'ids',
				'hide_empty' => false,
			)
		);
		return is_array( $children ) && array() !== $children;
	}

	/**
	 * Renders deletion target names without exposing internal details.
	 *
	 * @param list<array<string, mixed>> $items Selected inventory rows.
	 */
	private function render_delete_targets( array $items ): void {
		if ( array() === $items ) {
			?>
			<p><?php echo esc_html__( 'No deletion targets selected.', 'term-steward' ); ?></p>
			<?php
			return;
		}
		?>
		<ul class="term-steward-delete-list">
		<?php foreach ( $items as $item ) : ?>
			<li><?php echo esc_html( (string) $item['name'] ); ?></li>
		<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Returns the selected-term summary.
	 *
	 * @param int $count Selected count.
	 * @return string
	 */
	private function selected_count_label( int $count ): string {
		/* translators: %d: number of selected terms. */
		return 0 === $count ? __( 'No terms selected', 'term-steward' ) : sprintf( __( '%d terms selected', 'term-steward' ), $count );
	}

	/**
	 * Returns the configured-process summary.
	 *
	 * @param int $count Plan item count.
	 * @return string
	 */
	private function plan_count_label( int $count ): string {
		/* translators: %d: number of configured plan processes. */
		return sprintf( __( '%d processes configured', 'term-steward' ), $count );
	}

	/**
	 * Returns the current names of all source terms.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Plan item.
	 * @return string
	 */
	private function plan_source_names( Taxonomy $taxonomy, array $item ): string {
		$names = array();
		foreach ( $item['sources'] as $source ) {
			$term    = get_term( (int) $source['term_id'], $taxonomy->value );
			$names[] = $term instanceof WP_Term ? $term->name : __( 'Missing term', 'term-steward' );
		}
		return implode( ', ', $names );
	}

	/**
	 * Returns the planned destination or new value.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Plan item.
	 * @return string
	 */
	private function planned_change( Taxonomy $taxonomy, array $item ): string {
		if ( Action::RENAME->value === $item['action'] ) {
			return (string) $item['new_name'] . ( null !== $item['new_slug'] ? ' / ' . $item['new_slug'] : '' );
		}
		if ( Action::MERGE->value === $item['action'] ) {
			$term = get_term( (int) $item['destination']['term_id'], $taxonomy->value );
			return $term instanceof WP_Term ? $term->name : __( 'Missing destination', 'term-steward' );
		}
		return __( 'Delete after preview validation', 'term-steward' );
	}

	/**
	 * Returns current relationship estimates for a draft item.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Plan item.
	 * @return string
	 */
	private function estimated_scope( Taxonomy $taxonomy, array $item ): string {
		$published = array();
		$total     = array();
		foreach ( $item['sources'] as $source ) {
			$relationships = get_objects_in_term( (int) $source['term_id'], $taxonomy->value );
			if ( ! is_array( $relationships ) ) {
				continue;
			}
			foreach ( $relationships as $object_id ) {
				$total[] = (int) $object_id;
				$post    = get_post( (int) $object_id );
				if ( $post instanceof \WP_Post && 'post' === $post->post_type && 'publish' === $post->post_status ) {
					$published[] = $post->ID;
				}
			}
		}
		/* translators: 1: published post count, 2: total relationship count. */
		return sprintf( __( '%1$d published posts; %2$d total relationships', 'term-steward' ), count( array_unique( $published ) ), count( array_unique( $total ) ) );
	}

	/**
	 * Returns current merge warnings for a draft item.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Plan item.
	 * @return string
	 */
	private function draft_warnings( Taxonomy $taxonomy, array $item ): string {
		if ( Action::MERGE->value !== $item['action'] ) {
			return '';
		}
		$warnings = array();
		foreach ( $item['sources'] as $source ) {
			$relationships = get_objects_in_term( (int) $source['term_id'], $taxonomy->value );
			foreach ( is_array( $relationships ) ? $relationships : array() as $object_id ) {
				$post = get_post( (int) $object_id );
				if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
					$warnings['excluded'] = $this->warning_label( 'used_by_excluded_objects' );
				}
			}
			if ( Taxonomy::CATEGORY === $taxonomy ) {
				$children = get_terms(
					array(
						'taxonomy'   => $taxonomy->value,
						'parent'     => (int) $source['term_id'],
						'fields'     => 'ids',
						'hide_empty' => false,
					)
				);
				if ( is_array( $children ) && array() !== $children ) {
					$warnings['children'] = $this->warning_label( 'has_child_categories' );
				}
			}
		}
		return implode( ' ', $warnings );
	}

	/**
	 * Returns a translated action label.
	 *
	 * @param string $action Internal action value.
	 * @return string
	 */
	private function action_label( string $action ): string {
		return match ( $action ) {
			'rename' => __( 'Rename', 'term-steward' ),
			'merge'  => __( 'Merge', 'term-steward' ),
			default  => __( 'Delete', 'term-steward' ),
		};
	}

	/**
	 * Returns a meaningful parent value for categories and tags.
	 *
	 * @param Taxonomy $taxonomy    Current taxonomy.
	 * @param mixed    $parent_name Parent term name from the inventory query.
	 */
	private function parent_label( Taxonomy $taxonomy, mixed $parent_name ): string {
		if ( Taxonomy::POST_TAG === $taxonomy ) {
			return __( 'Not applicable', 'term-steward' );
		}

		return is_string( $parent_name ) && '' !== $parent_name
			? $parent_name
			: __( 'None', 'term-steward' );
	}

	/**
	 * Renders the request-level success or error summary.
	 *
	 * @param mixed $notice Notice code or null.
	 * @param array $errors Validation error codes.
	 */
	private function render_notice( mixed $notice, array $errors ): void {
		if ( array() !== $errors ) {
			?>
			<div class="notice notice-error inline term-steward-error-summary" role="alert"><p><strong><?php echo esc_html__( 'The request could not be completed.', 'term-steward' ); ?></strong></p></div>
			<?php
		} elseif ( is_string( $notice ) && '' !== $notice && 'preview_created' !== $notice ) {
			?>
			<div class="notice notice-success inline" role="status"><p><?php echo esc_html( $this->notice_label( $notice ) ); ?></p></div>
			<?php
		}
	}

	/**
	 * Returns a translated success notice.
	 *
	 * @param string $notice Notice code.
	 * @return string
	 */
	private function notice_label( string $notice ): string {
		return match ( $notice ) {
			'execution_updated' => __( 'Execution progress was updated.', 'term-steward' ),
			'plan_item_added'   => __( 'Added to the operation plan.', 'term-steward' ),
			'plan_item_removed' => __( 'The process was removed from the plan.', 'term-steward' ),
			'preview_created'   => __( 'The preview was created without changing WordPress data.', 'term-steward' ),
			'preview_invalidated' => __( 'The previous preview was invalidated. You can now revise the plan.', 'term-steward' ),
			default             => __( 'The plan was discarded.', 'term-steward' ),
		};
	}

	/**
	 * Renders validation errors beside the section or input that can resolve them.
	 *
	 * @param array<string, list<string>> $field_errors Errors grouped by stable field key.
	 * @param string                      $field        Field key to render.
	 * @param string                      $id_prefix    Unique message ID prefix.
	 */
	private function render_field_errors( array $field_errors, string $field, string $id_prefix ): void {
		foreach ( $field_errors[ $field ] ?? array() as $index => $error ) {
			?>
			<p id="<?php echo esc_attr( $id_prefix . '-' . $index ); ?>" class="term-steward-field-error" tabindex="-1" role="alert"><span class="dashicons dashicons-warning" aria-hidden="true"></span><strong><?php echo esc_html__( 'Error', 'term-steward' ); ?>:</strong> <?php echo esc_html( ErrorMessages::label( $error ) ); ?></p>
			<?php
		}
	}

	/**
	 * Returns every generated error ID for an aria-describedby relationship.
	 *
	 * @param array<string, list<string>> $field_errors Errors grouped by stable field key.
	 * @param string                      $field Field key.
	 * @param string                      $id_prefix Error element ID prefix.
	 * @return string
	 */
	private function field_error_ids( array $field_errors, string $field, string $id_prefix ): string {
		$ids = array();
		foreach ( array_keys( $field_errors[ $field ] ?? array() ) as $index ) {
			$ids[] = $id_prefix . '-' . $index;
		}
		return implode( ' ', $ids );
	}

	/**
	 * Selects the first input that can correct a validation error.
	 *
	 * @param array<string, list<string>> $field_errors Errors grouped by stable field key.
	 * @return string
	 */
	private function error_focus( array $field_errors ): string {
		foreach ( array( 'selection', 'operation', 'new_name', 'new_slug', 'destination', 'delete', 'plan' ) as $field ) {
			if ( isset( $field_errors[ $field ] ) ) {
				return $field;
			}
		}
		return '';
	}

	/**
	 * Returns a translated preview warning.
	 *
	 * @param string $warning Warning code.
	 * @return string
	 */
	private function warning_label( string $warning ): string {
		return 'has_child_categories' === $warning ? __( 'The source has child categories and will be retained.', 'term-steward' ) : __( 'The source is used outside published posts and will be retained.', 'term-steward' );
	}

	/**
	 * Returns a translated deletion or retention reason.
	 *
	 * @param string $reason Preview reason code.
	 * @return string
	 */
	private function reason_label( string $reason ): string {
		return match ( $reason ) {
			'globally_unused'                  => __( 'It has no relationships to any WordPress object.', 'term-steward' ),
			'safe_after_published_reassignment' => __( 'It can be removed after its published-post assignments are moved.', 'term-steward' ),
			'rename_preserves_term'             => __( 'Rename preserves the term and all relationships.', 'term-steward' ),
			'has_child_categories'              => __( 'It has child categories.', 'term-steward' ),
			default                             => __( 'It is used by excluded objects.', 'term-steward' ),
		};
	}

	/**
	 * Returns a translated relationship-usage label.
	 *
	 * @param string $usage Internal usage value.
	 * @return string
	 */
	private function usage_label( string $usage ): string {
		return match ( $usage ) {
			'published'     => __( 'Used by published posts', 'term-steward' ),
			'excluded_only' => __( 'Used outside published posts', 'term-steward' ),
			default         => __( 'Globally unused', 'term-steward' ),
		};
	}
}
