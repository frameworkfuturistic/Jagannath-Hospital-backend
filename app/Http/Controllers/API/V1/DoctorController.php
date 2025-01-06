<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\ConsultantShift;
use App\Models\OpdRegistration;
use App\Models\DoctorSlot;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DoctorController extends Controller
{
    // Fetch doctors based on department
    public function index($departmentId, $consultantId = null)
    {
        // Start the query by filtering by department ID
        $query = Consultant::where('DepartmentID', $departmentId)
            ->with('consultantShift'); // Eager load the consultant shift to get the fee

        // Add additional filter if ConsultantID is provided
        if ($consultantId) {
            $query->where('ConsultantID', $consultantId);
        }

        // Execute the query
        $doctors = $query->get();

        // Remove duplicate ConsultantName entries
        $doctorData = $doctors->unique('ConsultantName')->map(function ($doctor) {
            return [
                'ConsultantID' => $doctor->ConsultantID,
                'ConsultantName' => $doctor->ConsultantName,
                'Fee' => optional($doctor->consultantShift)->Fee, // Safely access Fee
            ];
        });

        return response()->json($doctorData, 200);
    }


    public function getAllConsultants()
    {
        // Eager load the department and consultant shift relationships
        $consultants = Consultant::with(['department', 'consultantShift'])->get();

        // Transform the data to include department name
        $consultantData = $consultants->map(function ($consultant) {
            return [
                'ConsultantID' => $consultant->ConsultantID,
                'ConsultantName' => $consultant->ConsultantName,
                'ProfessionalDegree' => $consultant->ProfessionalDegree,
                'Fee' => optional($consultant->consultantShift)->Fee, // Safely access Fee
                'Department' => optional($consultant->department)->Department // Safely access Department Name
            ];
        });

        return response()->json($consultantData, 200);
    }
}
