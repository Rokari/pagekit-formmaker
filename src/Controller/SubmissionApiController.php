<?php

namespace Pagekit\Formmaker\Controller;

use Pagekit\Application as App;
use Pagekit\Application\Exception;
use Pagekit\Formmaker\Model\Form;
use Pagekit\Formmaker\Model\Field;
use Pagekit\Formmaker\Model\Submission;
use Pagekit\Formmaker\Field\Fieldsubmission;

/**
 * @Route("submission", name="submission")
 */
class SubmissionApiController {

	/**
	 * @Access("formmaker: manage submissions")
	 * @Route("/", methods="GET")
	 * @Request({"filter": "array", "page":"int"})
	 */
	public function indexAction ($filter = [], $page = 0) {
		$query  = Submission::query();
		$filter = array_merge(array_fill_keys(['status', 'form', 'order', 'limit'], ''), $filter);

		extract($filter, EXTR_SKIP);

		if (is_numeric($status)) {
			$query->where(['status' => (int) $status]);
		}

		if ($form) {
			$query->where(function ($query) use ($form) { //todo understand this :)
				$query->orWhere(['form_id' => (int) $form]);
			});
		}

		if (!preg_match('/^(created|ip|email)\s(asc|desc)$/i', $order, $order)) {
			$order = [1 => 'created', 2 => 'desc'];
		}


		$limit = (int) $limit ?: App::module('formmaker')->config('submissions_per_page');
		$count = $query->count();
		$pages = ceil($count / $limit);
		$page  = max(0, min($pages - 1, $page));

		$submissions = array_values($query->offset($page * $limit)->related('form')->limit($limit)->orderBy($order[1], $order[2])->get());

		return compact('submissions', 'pages', 'count');

	}


	/**
	 * @Route("/", methods="POST")
	 * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
	 * @Request({"submission": "array", "id": "int"}, csrf=true)
	 */
	public function saveAction ($data, $id = 0) {

		if (!$submission = Submission::find($id)) {
			$submission = Submission::create();
			unset($data['id']);

			$submission->form_id = $data['form_id'];
			$submission->created = new \DateTime;
			$submission->ip = App::request()->getClientIp();
		}

		if (!$form = Form::find($submission->form_id)) {
			App::abort(404, 'Form not found.');
		}

		$submission->email = ''; //todo mailstuff

		$submission->form = $form; //todo from relations?

		try {

			$submission->save($data);

		} catch (Exception $e) {
			App::abort(400, $e->getMessage());
		}

		return ['message' => 'Submission successfull', 'submission' => $submission];
	}

	/**
	 * @Route("/detail")
	 * @Request({"id"})
	 */
	public function detailAction ($id) {
		$formmaker = App::module('formmaker');

		if (!$submission = Submission::where(['id = ?'], [$id])->related('form')->first()) {
			App::abort(404, 'Submission not found.');
		}
		$fields = $submission->form->getFields();

		foreach ($submission->data as $submissionvalue) {

			if (isset($fields[$submissionvalue['field_id']])) {

				$field = $fields[$submissionvalue['field_id']];

			} else {

				//field might be deleted form form
				$field = Field::create();
				$field->setType($submissionvalue['type']);
			}
			$submission->fieldsubmissions[$submissionvalue['field_id']] = (new Fieldsubmission($field, $submissionvalue))->toFormattedArray();
		}

		return $submission;
	}

	/**
	 * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
	 * @Request({"id": "int"}, csrf=true)
	 */
	public function deleteAction ($id) {
		if ($field = Field::find($id)) {

			$field->delete();
		}

		return ['message' => 'success'];
	}

	/**
	 * @Route("/bulk", methods="DELETE")
	 * @Request({"ids": "array"}, csrf=true)
	 */
	public function bulkDeleteAction ($ids = []) {
		foreach (array_filter($ids) as $id) {
			$this->deleteAction($id);
		}

		return ['message' => 'success'];
	}

}
